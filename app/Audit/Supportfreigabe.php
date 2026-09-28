<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\Ability;
use App\Enums\AuditEvent;
use App\Enums\Freigabeweg;
use App\Enums\OperatorAbility;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\SupportPin;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Freigabe des Vollzugriffs per Einmal-PIN (WP-34b, Entscheidung C15).
 *
 * **Die Praxis gibt frei, nicht der Betreiber.** Die PIN ist ein zweiter Weg
 * zu derselben Freigabe wie der Klick (Impersonation::approve), kein
 * Generalschluessel: einmal, kurz und nur fuer die eigene Praxis. Erzeugen
 * darf nur, wer per Klick freigeben darf -- also nie der Betreiber in der
 * Impersonation, der dort die Rolle einer Inhaberin spielt.
 */
final class Supportfreigabe
{
    /** Die Drosselung zaehlt je Stunde (`versuche_je_betreiber_stunde`). */
    private const STUNDE = 3600;

    public function __construct(
        private readonly AuditLogger $protokoll,
        private readonly TenantContext $mandant,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * Erzeugt eine PIN und gibt sie **genau einmal** im Klartext zurueck.
     *
     * Eine neue PIN macht die vorige ungueltig, auch eine noch gueltige: Es
     * gibt je Praxis hoechstens eine, und es ist die zuletzt genannte.
     */
    public function erzeuge(User $inhaberin): string
    {
        $praxis = $this->praxisVon($inhaberin);

        return $this->mandant->runAs($praxis, function () use ($inhaberin): string {
            $laenge = (int) config('mrs.support_pin.laenge');
            $pin = str_pad((string) random_int(0, 10 ** $laenge - 1), $laenge, '0', STR_PAD_LEFT);

            $neue = DB::transaction(function () use ($inhaberin, $pin): SupportPin {
                // **Auch abgelaufene** -- der Guard kennt die Uhr nicht, und
                // ohne das hier bekaeme die Inhaberin nach 15 Minuten keine
                // neue PIN.
                SupportPin::query()->offen()->update(['revoked_at' => now()]);

                $neue = new SupportPin;
                $neue->created_by_user_id = (string) $inhaberin->getKey();
                $neue->pin_hash = Hash::make($pin);
                $neue->expires_at = now()->addMinutes((int) config('mrs.support_pin.gueltig_minuten'));
                $neue->save();

                return $neue;
            });

            $this->protokoll->record(
                ereignis: AuditEvent::SupportPinCreated,
                gegenstand: $neue,
                kontext: ['gueltig_bis' => $neue->expires_at->toIso8601String()],
            );

            return $pin;
        });
    }

    /** Widerruft die offene PIN der eigenen Praxis, wenn es eine gibt. */
    public function widerrufe(User $inhaberin): void
    {
        $praxis = $this->praxisVon($inhaberin);

        $this->mandant->runAs($praxis, function (): void {
            $widerrufen = SupportPin::query()->offen()->update(['revoked_at' => now()]);

            if ($widerrufen > 0) {
                $this->protokoll->record(ereignis: AuditEvent::SupportPinRevoked);
            }
        });
    }

    /**
     * Loest eine PIN in der laufenden Sitzung des Betreibers ein. Bei Erfolg
     * hat die Sitzung Vollzugriff, freigegeben von der Inhaberin, die die PIN
     * erzeugt hat.
     *
     * @throws SupportPinAbgelehnt bei jeder PIN, die nicht wirkt
     */
    public function loese(ImpersonationSession $sitzung, User $betreiber, string $pin): ImpersonationSession
    {
        // Finanzen kommt nie in eine Praxis (WP-34a) -- auch nicht per PIN.
        if (! $betreiber->betreiberDarf(OperatorAbility::SupportZugriff)) {
            throw new RuntimeException('Eine PIN loest nur ein Betreiberkonto mit Support-Zugriff ein.');
        }

        if ($sitzung->getAttribute('impersonator_user_id') !== $betreiber->getKey() || ! $sitzung->laeuft()) {
            throw new RuntimeException('Diese Sitzung laeuft nicht mehr oder gehoert jemand anderem.');
        }

        $praxis = Organization::query()->whereKey($sitzung->organization_id)->firstOrFail();
        $drossel = "support-pin:fehlversuche:{$betreiber->uuid}";

        return $this->mandant->runAs($praxis, function () use ($sitzung, $betreiber, $pin, $drossel): ImpersonationSession {
            // **Je Betreiber ueber alle Praxen**, nicht je PIN: sonst hilft
            // die Grenze nicht gegen hundert Praxen mit je vier Versuchen.
            if (RateLimiter::tooManyAttempts($drossel, (int) config('mrs.support_pin.versuche_je_betreiber_stunde'))) {
                $this->protokoll->record(ereignis: AuditEvent::SupportPinFailed, kontext: ['gedrosselt' => true]);

                throw SupportPinAbgelehnt::gedrosselt(RateLimiter::availableIn($drossel));
            }

            $eingabe = preg_replace('/\s+/', '', $pin) ?? '';

            // Die Transaktion **liefert** das Ergebnis, statt zu werfen: ein
            // Fehlversuch muss festgeschrieben sein, bevor die Ablehnung
            // fliegt -- sonst rollte der Zaehler mit ihr zurueck.
            $angenommen = DB::transaction(function () use ($sitzung, $betreiber, $eingabe): bool {
                $offene = SupportPin::query()->offen()->lockForUpdate()->first();

                // Auch ohne offene PIN wird gerechnet: Die Antwort auf „keine
                // PIN" darf nicht schneller kommen als die auf „falsche PIN".
                $stimmt = Hash::check($eingabe, $offene instanceof SupportPin ? $offene->pin_hash : $this->attrappe())
                    && preg_match('/^\d{'.(int) config('mrs.support_pin.laenge').'}$/', $eingabe) === 1;

                if (! $offene instanceof SupportPin || ! $offene->istGueltig()) {
                    $this->protokoll->record(ereignis: AuditEvent::SupportPinFailed);

                    return false;
                }

                if (! $stimmt) {
                    $offene->failed_attempts++;
                    $verbrannt = $offene->failed_attempts >= (int) config('mrs.support_pin.max_versuche');

                    if ($verbrannt) {
                        $offene->revoked_at = now();
                    }

                    $offene->save();

                    $this->protokoll->record(
                        ereignis: $verbrannt ? AuditEvent::SupportPinBurned : AuditEvent::SupportPinFailed,
                        gegenstand: $offene,
                        kontext: ['versuche' => $offene->failed_attempts],
                    );

                    return false;
                }

                $inhaberin = User::query()->whereKey($offene->created_by_user_id)->first();

                if (! $inhaberin instanceof User) {
                    throw new RuntimeException('Die Person, die diese PIN erzeugt hat, gibt es nicht mehr.');
                }

                $offene->used_at = now();
                $offene->used_by_user_id = (string) $betreiber->getKey();
                $offene->impersonation_session_id = (string) $sitzung->getKey();
                $offene->save();

                $this->protokoll->record(ereignis: AuditEvent::SupportPinRedeemed, gegenstand: $offene);

                // Dieselbe Freigabe wie per Klick. Sie nennt die Inhaberin,
                // nicht den Betreiber.
                $this->impersonation->approve($sitzung, $inhaberin, Freigabeweg::Pin);

                return true;
            });

            if (! $angenommen) {
                RateLimiter::hit($drossel, self::STUNDE);

                throw SupportPinAbgelehnt::falsch();
            }

            return $sitzung;
        });
    }

    /**
     * Nur wer per Klick freigeben darf, erzeugt oder widerruft eine PIN.
     *
     * An der Faehigkeit, nicht an der Rolle: Der Betreiber in der
     * Impersonation spielt die Rolle einer Inhaberin, die Faehigkeit
     * ApproveImpersonation fehlt ihm ausdruecklich (User::faehigkeitAlsSupport).
     */
    private function praxisVon(User $person): Organization
    {
        if (! $person->hasAbility(Ability::ApproveImpersonation) || $person->organization_id === null) {
            throw new RuntimeException('Eine Einmal-PIN erzeugt nur, wer den Vollzugriff freigeben darf.');
        }

        return Organization::query()->whereKey($person->organization_id)->firstOrFail();
    }

    /** Ein Hash, gegen den nichts stimmt -- fuer die Rechnung ohne PIN. */
    private function attrappe(): string
    {
        /** @var string */
        return Cache::rememberForever('support-pin:attrappe', fn (): string => Hash::make(Str::random(40)));
    }
}
