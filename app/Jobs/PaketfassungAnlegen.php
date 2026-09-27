<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\PlanVersion;
use App\Support\Fehlereinordnung;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Legt die Preise einer neuen Paketfassung bei Stripe an (WP-06b, B20).
 *
 * **Nicht im Anfragezyklus** (Regel 4), und jeder Aufruf mit einem
 * Idempotenzschluessel aus Fassung und Preisart: ein wiederholter Auftrag legt
 * keinen fuenften Preis an.
 *
 * **Erst wenn alle vier Preise stehen, gilt die Fassung.** Vorher kassiert die
 * Kasse weiter unter der vorigen -- eine Kasse, die auf fehlende Preise zeigt,
 * waere eine Fehlermeldung von Stripe statt einer Kasse.
 *
 * Danach werden Grund- und Einrichtungspreis der vorigen Fassung archiviert.
 * Das sperrt sie fuer neue Abschluesse; **bestehende Abos rechnen weiter** --
 * genau das ist der Bestandsschutz.
 */
final class PaketfassungAnlegen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $fassung)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->fassung;
    }

    public function handle(Stripeclient $stripe, Paket $paket, AuditLogger $protokoll): void
    {
        $fassung = PlanVersion::query()->whereUuid($this->fassung)->first();

        if (! $fassung instanceof PlanVersion || $fassung->stripe_state !== PlanVersion::AUSSTEHEND) {
            return;
        }

        $vorige = PlanVersion::query()
            ->whereNotNull('activated_at')
            ->where('number', '<', $fassung->number)
            ->orderByDesc('number')
            ->first();

        try {
            $this->legeAn($stripe, $fassung, $vorige);
        } catch (DomainException $ablehnung) {
            $this->scheitere($fassung, $ablehnung->getMessage(), $protokoll);

            return;
        }

        $paket->giltAb($fassung);

        if ($vorige instanceof PlanVersion) {
            $this->archiviere($stripe, $fassung, $vorige);
        }
    }

    public function failed(?Throwable $fehler): void
    {
        $fassung = PlanVersion::query()->whereUuid($this->fassung)->first();

        if ($fassung instanceof PlanVersion && $fassung->stripe_state === PlanVersion::AUSSTEHEND) {
            $this->scheitere($fassung, 'Stripe war auch nach mehreren Versuchen nicht erreichbar.', app(AuditLogger::class));
        }
    }

    /**
     * Produkt, Name, vier Preise -- jeder Schritt einzeln gespeichert, damit
     * eine Wiederholung dort weitermacht, wo die letzte aufgehoert hat.
     */
    private function legeAn(Stripeclient $stripe, PlanVersion $fassung, ?PlanVersion $vorige): void
    {
        $kennung = (string) $fassung->uuid;

        // **Unter dem bisherigen Produkt.** Gelesen ueber den alten Preis --
        // es gibt keine zweite Konfiguration dafuer.
        $produkt = $fassung->stripe_product_id ?? $this->produktVon($stripe, $vorige);

        if ($produkt === null) {
            $produkt = (string) $this->antwort($stripe->legeProduktAn($fassung->name, $kennung.'-produkt'))['id'];
        } elseif ($vorige instanceof PlanVersion && $vorige->name !== $fassung->name && $fassung->stripe_price_base === null) {
            // Vor dem ersten Preis -- eine Wiederholung benennt nicht noch
            // einmal um.
            $this->antwort($stripe->benenneProdukt($produkt, $fassung->name, $kennung.'-name'));
        }

        $fassung->stripe_product_id = $produkt;
        $fassung->save();

        foreach ([
            'stripe_price_base' => [$fassung->base_cents, true, 'Grundpreis'],
            'stripe_price_setup' => [$fassung->setup_cents, false, 'Einrichtung'],
            'stripe_price_topup' => [$fassung->topup_cents, false, 'Aufstockung'],
            'stripe_price_image' => [$fassung->image_price_cents, false, 'Anzeigenbild'],
        ] as $feld => [$cent, $monatlich, $bezeichnung]) {
            // Schon angelegt -- oder eine Einrichtung, die nichts kostet.
            if ($fassung->getAttribute($feld) !== null || $cent === 0) {
                continue;
            }

            $preis = $this->antwort($stripe->legePreisAn(
                $produkt,
                $cent,
                $monatlich,
                "{$bezeichnung} · Fassung {$fassung->number}",
                $kennung.'-'.$feld,
            ));

            $fassung->setAttribute($feld, (string) $preis['id']);
            $fassung->save();
        }
    }

    private function produktVon(Stripeclient $stripe, ?PlanVersion $vorige): ?string
    {
        if (! $vorige instanceof PlanVersion) {
            return null;
        }

        if ($vorige->stripe_product_id !== null) {
            return $vorige->stripe_product_id;
        }

        if ($vorige->stripe_price_base === null) {
            return null;
        }

        $produkt = $this->antwort($stripe->preis($vorige->stripe_price_base))['product'] ?? null;

        return is_string($produkt) ? $produkt : null;
    }

    /**
     * Die alten Preise fuer neue Abschluesse sperren. **Nicht kritisch**: ein
     * Preis, der noch aktiv ist, schadet nicht, solange die Kasse ihn nicht
     * anbietet -- und das tut sie nicht mehr.
     *
     * **Nur Grundpreis und Einrichtung.** Aufstockung und Bild kauft eine
     * Praxis im Bestandsschutz weiter zum Preis ihrer Fassung, und Stripes
     * Kasse nimmt keinen archivierten Preis an: wer sie archivierte, sperrte
     * genau den Praxen das Nachkaufen, die er schuetzen wollte.
     */
    private function archiviere(Stripeclient $stripe, PlanVersion $fassung, PlanVersion $vorige): void
    {
        foreach (['stripe_price_base', 'stripe_price_setup'] as $feld) {
            $alt = $vorige->getAttribute($feld);

            if (! is_string($alt) || $alt === $fassung->getAttribute($feld)) {
                continue;
            }

            try {
                $antwort = $stripe->archivierePreis($alt, $fassung->uuid.'-archiv-'.$feld);

                if (! $antwort->successful()) {
                    Log::warning('Alter Stripe-Preis liess sich nicht archivieren', ['preis' => $alt, 'status' => $antwort->status()]);
                }
            } catch (ConnectionException) {
                Log::warning('Alter Stripe-Preis liess sich nicht archivieren', ['preis' => $alt]);
            }
        }
    }

    /**
     * Die Antwort -- oder eine Wiederholung (Ausfall) oder eine endgueltige
     * Ablehnung.
     *
     * @return array<string, mixed>
     */
    private function antwort(Response $antwort): array
    {
        if ($antwort->successful()) {
            return (array) $antwort->json();
        }

        /** @var array<string, mixed> $koerper */
        $koerper = (array) $antwort->json();
        $einordnung = Fehlereinordnung::ausStripeAntwort($antwort->status(), $koerper);

        if ($einordnung->wiederholen) {
            throw new RuntimeException("Stripe antwortet mit {$antwort->status()}; die Fassung wird erneut versucht.");
        }

        throw new DomainException($einordnung->grund());
    }

    private function scheitere(PlanVersion $fassung, string $grund, AuditLogger $protokoll): void
    {
        $fassung->stripe_state = PlanVersion::GESCHEITERT;
        $fassung->stripe_error = mb_substr($grund, 0, 500);
        $fassung->save();

        $protokoll->record(
            ereignis: AuditEvent::PlanVersionFailed,
            gegenstand: $fassung,
            kontext: ['fassung' => $fassung->number],
            ohneOrganisation: true,
        );
    }
}
