<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Abrechnung\Paket;
use App\Enums\MessageCostCategory;
use App\Enums\SubscriptionAccess;
use App\Models\AdSuggestionImage;
use App\Models\AgentRun;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\MonthlyClosing;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\TopUp;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Was die Praxen dem Betreiber einbringen und was sie ihn kosten (WP-34d).
 *
 * **Eine Hochrechnung, keine Buchhaltung. Das sagt die Seite auch** (B19).
 * Einnahmen sind Preis mal Zustand, Kosten Menge mal Satz -- aus Zahlen, die
 * im Produkt ohnehin stehen. Rechnungen, Erstattungen und Steuern bleiben
 * bei Stripe.
 *
 * **Eine Abfrage je Quelle, nicht je Praxis**: gruppiert nach
 * `organization_id`, in genau einem Querzugriff. Die Methoden von
 * Nutzungsuebersicht zaehlen im laufenden Mandanten und lieferten im
 * Querzugriff die ganze Installation. Die Bedingungen, die beide teilen,
 * stehen als Scopes an den Modellen.
 *
 * **Zahlen je Praxis und Monat, nie je Person und nie je Tag.**
 */
final class Finanzuebersicht
{
    /** Die Saetze, die fehlen koennen, und wie die Seite sie nennt. */
    private const SAETZE = [
        'usd_eur' => 'Dollarkurs',
        'whatsapp.marketing' => 'WhatsApp Marketing',
        'whatsapp.utility' => 'WhatsApp Utility',
        'whatsapp.authentication' => 'WhatsApp Authentifizierung',
        'whatsapp.service' => 'WhatsApp Service-Fenster',
        'bild.1x1' => 'Bild 1:1',
        'bild.4x5' => 'Bild 4:5',
        'bild.9x16' => 'Bild 9:16',
        'stripe' => 'Stripe-Gebühren',
    ];

    /** Was eine Bildaufstockung ist -- alles andere stockt Kontingent auf. */
    private const BILDER = 'bilder';

    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Paket $paket,
    ) {}

    /**
     * Die Seite des Betreibers: Kennzahlen, Verlauf und Praxiszeilen in
     * **einem** Querzugriff (AK 18).
     *
     * @return array<string, mixed>
     */
    public function bericht(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        /** @var array<string, mixed> */
        return $this->mandant->acrossTenants(
            'Backoffice zeigt dem Betreiber Einnahmen und Kosten seiner Praxen (WP-34d)',
            function () use ($jetzt): array {
                $praxen = $this->praxen();
                $aktuell = $this->rechne($praxen, $jetzt, $jetzt);

                return [
                    'monat' => $aktuell->monat,
                    'kennzahlen' => $this->kennzahlen($aktuell),
                    'verlauf' => $this->verlauf($praxen, $jetzt, (int) config('mrs.backoffice.finanzen_monate'), $aktuell),
                    'praxen' => array_map(fn (Praxisergebnis $zeile): array => $zeile->toArray(), $aktuell->praxen),
                    'summe' => $aktuell->summe()->toArray(),
                    'fehlendeSaetze' => self::benenne($aktuell->summe()->fehlendeSaetze),
                ];
            },
        );
    }

    /**
     * Ein Monat fuer alle Praxen, im eigenen Querzugriff.
     *
     * `$stand` ist der Zeitpunkt, zu dem der Zustand der Abos gilt: Fuer den
     * laufenden Monat jetzt, fuer den Abschluss der Moment des Laufs -- das
     * Abo kennt nichts anderes.
     */
    public function monat(CarbonImmutable $monat, ?CarbonImmutable $stand = null): Finanzmonat
    {
        return $this->mandant->acrossTenants(
            'Finanzuebersicht rechnet einen Monat fuer alle Praxen (WP-34d)',
            fn (): Finanzmonat => $this->rechne($this->praxen(), $monat, $stand ?? CarbonImmutable::now()),
        );
    }

    /**
     * Die letzten Monate einer Praxis fuer das Mandantenblatt, der neueste
     * zuerst. **Im Mandanten**, nicht quer: Das Blatt ist eine Praxis.
     *
     * @return list<array<string, mixed>>
     */
    public function fuerPraxis(Organization $praxis, ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        /** @var list<array<string, mixed>> */
        return $this->mandant->runAs($praxis, function () use ($praxis, $jetzt): array {
            $praxen = new Collection([$praxis]);
            $aktuell = $this->rechne($praxen, $jetzt, $jetzt);

            return array_reverse($this->verlauf($praxen, $jetzt, (int) config('mrs.backoffice.wirtschaftlichkeit_monate'), $aktuell));
        });
    }

    /**
     * Alle Praxen -- **auch gesperrte**: Eine gesperrte Praxis kann im
     * Vormonat noch gezahlt haben. Muss im Querzugriff laufen.
     *
     * @return Collection<int, Organization>
     */
    public function praxen(): Collection
    {
        return Organization::query()->get(['id', 'name', 'created_at'])
            ->sortBy(fn (Organization $praxis): string => mb_strtolower((string) $praxis->name))
            ->values();
    }

    /**
     * Rechnet einen Monat fuer die gegebenen Praxen. Eine Abfrage je Quelle.
     *
     * @param  Collection<int, Organization>  $praxen
     */
    public function rechne(Collection $praxen, CarbonImmutable $monat, CarbonImmutable $stand): Finanzmonat
    {
        $von = $monat->startOfMonth();
        $bis = $monat->endOfMonth();
        $kennungen = array_values($praxen->map(fn (Organization $praxis): string => (string) $praxis->getKey())->all());
        $fixkosten = $this->satz('fixkosten_cent_monat');

        if ($kennungen === []) {
            return new Finanzmonat($monat->format('Y-m'), [], $fixkosten);
        }

        $abos = Subscription::query()
            ->whereIn('organization_id', $kennungen)
            ->get([
                'organization_id', 'plan_version_id', 'status', 'stripe_customer_id', 'stripe_subscription_id',
                'trial_ends_at', 'paused_at', 'discount_ends_at', 'activated_at', 'cancel_at_period_end', 'canceled_at',
            ])
            ->keyBy(fn (Subscription $abo): string => (string) $abo->getAttribute('organization_id'));

        // Wenige Zeilen -- einmal geladen statt je Abo. **Der Preis steht an
        // der Fassung** (B20): Wer auf Fassung 1 abschloss, zahlt Fassung 1.
        $fassungen = PlanVersion::query()->get()->keyBy(fn (PlanVersion $fassung): string => (string) $fassung->getKey());
        $aktuell = $this->paket->aktuell();

        /** @var array<string, array<string, array{betrag: int, anzahl: int}>> $aufstockungen */
        $aufstockungen = [];
        foreach (TopUp::query()
            ->whereIn('organization_id', $kennungen)
            ->whereBetween('paid_at', [$von, $bis])
            ->selectRaw('organization_id, article, sum(amount_cents) as betrag, count(*) as anzahl')
            ->groupBy('organization_id', 'article')
            ->toBase()
            ->get() as $zeile) {
            $aufstockungen[(string) $zeile->organization_id][(string) $zeile->article] = ['betrag' => (int) $zeile->betrag, 'anzahl' => (int) $zeile->anzahl];
        }

        // Der Preis an die Praxis -- **Einnahme**, nicht Kosten.
        $servicefenster = Message::query()
            ->imServicefenster()
            ->whereIn('organization_id', $kennungen)
            ->whereBetween('created_at', [$von, $bis])
            ->selectRaw('organization_id, sum(charge_tenth_cents) as preis')
            ->groupBy('organization_id')
            ->toBase()
            ->pluck('preis', 'organization_id');

        // Was Meta uns berechnet: Anzahl je Kategorie mal Satz.
        /** @var array<string, array<string, int>> $nachrichten */
        $nachrichten = [];
        foreach (Message::query()
            ->ausgehend()
            ->whereNotNull('cost_category')
            ->whereIn('organization_id', $kennungen)
            ->whereBetween('created_at', [$von, $bis])
            ->selectRaw('organization_id, cost_category, count(*) as anzahl')
            ->groupBy('organization_id', 'cost_category')
            ->toBase()
            ->get() as $zeile) {
            $nachrichten[(string) $zeile->organization_id][(string) $zeile->cost_category] = (int) $zeile->anzahl;
        }

        $agent = $this->summeJePraxis(AgentRun::query(), $kennungen, $von, $bis);
        $anzeigen = $this->summeJePraxis(ModelCall::query(), $kennungen, $von, $bis);

        // **Eine Datei ist nicht ein Satz**: kie.ai rechnet je Format ab.
        /** @var array<string, array<string, int>> $bilder */
        $bilder = [];
        foreach (AdSuggestionImage::query()
            ->whereIn('organization_id', $kennungen)
            ->whereBetween('created_at', [$von, $bis])
            ->selectRaw('organization_id, format, count(*) as anzahl')
            ->groupBy('organization_id', 'format')
            ->toBase()
            ->get() as $zeile) {
            $bilder[(string) $zeile->organization_id][(string) $zeile->format] = (int) $zeile->anzahl;
        }

        $zeilen = [];

        foreach ($praxen as $praxis) {
            $kennung = (string) $praxis->getKey();

            /** @var Subscription|null $abo */
            $abo = $abos->get($kennung);
            $fassung = $abo instanceof Subscription
                ? ($fassungen->get((string) ($abo->getAttributes()['plan_version_id'] ?? '')) ?? $aktuell)
                : $aktuell;

            $zeilen[] = $this->zeile(
                praxis: $praxis,
                abo: $abo,
                fassung: $fassung,
                von: $von,
                bis: $bis,
                stand: $stand,
                aufstockungen: $aufstockungen[$kennung] ?? [],
                servicefensterZehntelCent: (int) ($servicefenster->get($kennung) ?? 0),
                nachrichten: $nachrichten[$kennung] ?? [],
                agentZehntelUsCent: (int) ($agent->get($kennung) ?? 0),
                anzeigenZehntelUsCent: (int) ($anzeigen->get($kennung) ?? 0),
                bilder: $bilder[$kennung] ?? [],
            );
        }

        return new Finanzmonat($monat->format('Y-m'), $zeilen, $fixkosten);
    }

    /**
     * Die Anzeigenamen fehlender Saetze.
     *
     * @param  list<string>  $schluessel
     * @return list<string>
     */
    public static function benenne(array $schluessel): array
    {
        return array_map(fn (string $satz): string => self::SAETZE[$satz] ?? $satz, $schluessel);
    }

    /**
     * @param  array<string, array{betrag: int, anzahl: int}>  $aufstockungen  je Artikel
     * @param  array<string, int>  $nachrichten  Anzahl je Kategorie
     * @param  array<string, int>  $bilder  Dateien je Format
     */
    private function zeile(
        Organization $praxis,
        ?Subscription $abo,
        PlanVersion $fassung,
        CarbonImmutable $von,
        CarbonImmutable $bis,
        CarbonImmutable $stand,
        array $aufstockungen,
        int $servicefensterZehntelCent,
        array $nachrichten,
        int $agentZehntelUsCent,
        int $anzeigenZehntelUsCent,
        array $bilder,
    ): Praxisergebnis {
        /** @var CarbonImmutable $angelegt */
        $angelegt = $praxis->getAttribute('created_at');

        // **Dieselbe Stelle wie ueberall** (WP-34c): Was das Abo erlaubt,
        // weiss Subscription::zugang(). Das Ende der Testphase kommt aus den
        // geladenen Zeilen, sonst fragte jedes Abo nach seiner Praxis.
        $testphasenende = $abo->trial_ends_at ?? $angelegt->addDays($fassung->trial_days);
        $zugang = $abo instanceof Subscription
            ? $abo->zugang($stand, $testphasenende)
            : ($testphasenende->greaterThan($stand) ? SubscriptionAccess::Trial : SubscriptionAccess::TrialExpired);

        $mitStripeAbo = is_string($abo?->stripe_subscription_id) && $abo->stripe_subscription_id !== '';
        $mitStripe = is_string($abo?->stripe_customer_id) && $abo->stripe_customer_id !== '';
        $offen = $abo instanceof Subscription && $zugang === SubscriptionAccess::Open;

        $grundpreis = $offen && $abo->rechnetGrundpreisAb($stand) ? $fassung->base_cents : 0;

        // **Genau einmal**, im Monat der ersten Aktivierung -- eine Pause mit
        // Fortsetzung setzt activated_at nicht neu.
        $einrichtung = $abo?->activated_at instanceof CarbonImmutable && $abo->activated_at->between($von, $bis)
            ? $fassung->setup_cents
            : 0;

        $bildkaeufe = $aufstockungen[self::BILDER]['betrag'] ?? 0;
        $blockkaeufe = array_sum(array_column(array_diff_key($aufstockungen, [self::BILDER => true]), 'betrag'));
        $kaeufe = array_sum(array_column($aufstockungen, 'anzahl'));

        // Nur mit laufendem Stripe-Abo: ohne landet der Sammelposten nie auf
        // einer Rechnung (Servicefensterabrechnung).
        $servicefenster = $mitStripeAbo ? (int) round($servicefensterZehntelCent / 10) : 0;

        $fehlend = [];

        $whatsappZehntelUsCent = 0;
        foreach ($nachrichten as $wert => $anzahl) {
            $kategorie = MessageCostCategory::tryFrom($wert);

            if (! $kategorie instanceof MessageCostCategory || ! $kategorie->kostetGeld()) {
                continue;
            }

            $satz = $this->satzJe('whatsapp_zehntel_us_cent', $kategorie->value);

            if ($satz === null) {
                $fehlend[] = 'whatsapp.'.$kategorie->value;

                continue;
            }

            $whatsappZehntelUsCent += $anzahl * $satz;
        }

        $bilderZehntelUsCent = 0;
        foreach ($bilder as $format => $anzahl) {
            $satz = $this->satzJe('bild_format_zehntel_us_cent', $format);

            if ($satz === null) {
                $fehlend[] = 'bild.'.$format;

                continue;
            }

            $bilderZehntelUsCent += $anzahl * $satz;
        }

        // **US-Cent sind keine Euro-Cent.** Ohne Kurs keine Umrechnung -- und
        // kein stiller Kurs von 1.
        $kurs = $this->kurs();
        $inEuro = fn (int $zehntelUsCent): int => $kurs === null ? 0 : (int) round($zehntelUsCent / 10 * $kurs);

        if ($kurs === null && $agentZehntelUsCent + $anzeigenZehntelUsCent + $whatsappZehntelUsCent + $bilderZehntelUsCent > 0) {
            $fehlend[] = 'usd_eur';
        }

        $einnahmen = $grundpreis + $einrichtung + $blockkaeufe + $bildkaeufe + $servicefenster;

        // Eine Rechnung je Monat mit Abo-Posten, eine je Kasse. Nur wer ueber
        // Stripe zahlt, kostet Stripe etwas -- der Testbetrieb nicht.
        $rechnungen = $mitStripe ? ($grundpreis + $einrichtung + $servicefenster > 0 ? 1 : 0) + $kaeufe : 0;
        $prozent = $this->kommazahl('stripe_prozent');
        $fix = $this->satz('stripe_fix_cent');

        if ($rechnungen > 0 && ($prozent === null || $fix === null)) {
            $fehlend[] = 'stripe';
        }

        $zahlungsverkehr = $rechnungen > 0
            ? (int) round($einnahmen * ($prozent ?? 0) / 100) + ($fix ?? 0) * $rechnungen
            : 0;

        $fehlend = array_values(array_unique($fehlend));
        sort($fehlend);

        return new Praxisergebnis(
            uuid: (string) $praxis->uuid,
            name: (string) $praxis->name,
            zugang: $zugang,
            grundpreis: $grundpreis,
            einrichtung: $einrichtung,
            aufstockungen: $blockkaeufe,
            bilder: $bildkaeufe,
            servicefenster: $servicefenster,
            sprachmodellAgent: $inEuro($agentZehntelUsCent),
            sprachmodellAnzeigen: $inEuro($anzeigenZehntelUsCent),
            whatsapp: $inEuro($whatsappZehntelUsCent),
            bildkosten: $inEuro($bilderZehntelUsCent),
            zahlungsverkehr: $zahlungsverkehr,
            fehlendeSaetze: $fehlend,
            kuendigungVorgemerkt: $offen && $abo->cancel_at_period_end,
            imMonatGekuendigt: $abo?->canceled_at instanceof CarbonImmutable && $abo->canceled_at->between($von, $bis),
        );
    }

    /**
     * Die Kennzahlen des laufenden Monats.
     *
     * @return array<string, mixed>
     */
    private function kennzahlen(Finanzmonat $monat): array
    {
        $summe = $monat->summe();
        $zaehle = fn (callable $bedingung): int => count(array_filter($monat->praxen, $bedingung));

        return [
            // MRR: der Grundpreis aller Praxen, die ihn gerade bringen.
            'mrrCent' => $summe->grundpreis,
            'arrCent' => $summe->grundpreis * 12,
            'zahlend' => $zaehle(fn (Praxisergebnis $z): bool => $z->grundpreis > 0),
            'testphase' => $zaehle(fn (Praxisergebnis $z): bool => $z->zugang === SubscriptionAccess::Trial),
            'testphaseAbgelaufen' => $zaehle(fn (Praxisergebnis $z): bool => $z->zugang === SubscriptionAccess::TrialExpired),
            'pausiert' => $zaehle(fn (Praxisergebnis $z): bool => $z->zugang === SubscriptionAccess::Paused),
            'gekuendigtZumPeriodenende' => $zaehle(fn (Praxisergebnis $z): bool => $z->kuendigungVorgemerkt),
            'kuendigungenImMonat' => $zaehle(fn (Praxisergebnis $z): bool => $z->imMonatGekuendigt),
            'einnahmenCent' => $summe->einnahmen(),
            'kostenCent' => $summe->kosten(),
            'rohertragCent' => $monat->rohertrag(),
            'marge' => $monat->marge(),
            'fixkostenCent' => $monat->fixkosten,
            'ergebnisCent' => $monat->ergebnis(),
            'unvollstaendig' => $summe->unvollstaendig(),
        ];
    }

    /**
     * Die Monate bis einschliesslich des laufenden, der aelteste zuerst.
     *
     * Der laufende wird gerechnet, vergangene kommen aus den Abschluessen.
     * **Vor dem ersten Abschluss: keine Daten** -- rueckwirkend laesst sich
     * der Zustand eines Abos nicht rechnen.
     *
     * @param  Collection<int, Organization>  $praxen
     * @return list<array<string, mixed>>
     */
    private function verlauf(Collection $praxen, CarbonImmutable $jetzt, int $monate, Finanzmonat $aktuell): array
    {
        $vergangene = [];
        for ($zurueck = $monate - 1; $zurueck >= 1; $zurueck--) {
            $vergangene[] = $jetzt->startOfMonth()->subMonthsNoOverflow($zurueck)->format('Y-m');
        }

        $namen = $praxen->mapWithKeys(fn (Organization $praxis): array => [
            (string) $praxis->getKey() => [(string) $praxis->uuid, (string) $praxis->name],
        ]);

        $abschluesse = $vergangene === []
            ? new Collection
            : MonthlyClosing::query()
                ->whereIn('organization_id', $namen->keys()->all())
                ->whereIn('month', $vergangene)
                ->get()
                ->groupBy('month');

        $verlauf = [];
        foreach ($vergangene as $monat) {
            /** @var Collection<int, MonthlyClosing> $zeilen */
            $zeilen = $abschluesse->get($monat, new Collection);

            if ($zeilen->isEmpty()) {
                $verlauf[] = Finanzmonat::ohneDaten($monat);

                continue;
            }

            $verlauf[] = (new Finanzmonat(
                $monat,
                array_values($zeilen->map(function (MonthlyClosing $abschluss) use ($namen): Praxisergebnis {
                    [$uuid, $name] = $namen->get((string) $abschluss->getAttribute('organization_id'), ['', '']);

                    return Praxisergebnis::ausAbschluss($abschluss, $uuid, $name);
                })->all()),
                $aktuell->fixkosten,
            ))->toArray();
        }

        $verlauf[] = $aktuell->toArray();

        return $verlauf;
    }

    /**
     * Summe von `cost_tenth_cents` je Praxis im Zeitraum.
     *
     * @param  Builder<AgentRun>|Builder<ModelCall>  $abfrage
     * @param  list<string>  $kennungen
     * @return Collection<string, mixed>
     */
    private function summeJePraxis($abfrage, array $kennungen, CarbonImmutable $von, CarbonImmutable $bis): Collection
    {
        return $abfrage
            ->whereIn('organization_id', $kennungen)
            ->whereBetween('created_at', [$von, $bis])
            ->selectRaw('organization_id, sum(cost_tenth_cents) as kosten')
            ->groupBy('organization_id')
            ->toBase()
            ->pluck('kosten', 'organization_id');
    }

    private function kurs(): ?float
    {
        return $this->kommazahl('usd_eur');
    }

    private function satz(string $schluessel): ?int
    {
        $wert = config('mrs.backoffice.kosten.'.$schluessel);

        return is_numeric($wert) ? (int) $wert : null;
    }

    private function kommazahl(string $schluessel): ?float
    {
        $wert = config('mrs.backoffice.kosten.'.$schluessel);

        return is_numeric($wert) ? (float) $wert : null;
    }

    private function satzJe(string $gruppe, string $schluessel): ?int
    {
        $saetze = config('mrs.backoffice.kosten.'.$gruppe);
        $wert = is_array($saetze) ? ($saetze[$schluessel] ?? null) : null;

        return is_numeric($wert) ? (int) $wert : null;
    }
}
