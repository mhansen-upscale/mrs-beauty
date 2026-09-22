<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Enums\WaitlistOfferStatus;
use App\Models\Appointment;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;

/**
 * Was die Warteliste eingebracht hat.
 *
 * **Diese Auswertung ist das Verkaufsargument im Demo-Termin** und gehoert
 * deshalb ins Produkt (docs/fachlogik/warteliste.md, Abschnitt Kennzahlen) --
 * nicht in eine Auswertung, die jemand auf Anfrage baut.
 *
 * Der Wert einer gefuellten Luecke ist `treatments.avg_revenue_cents` und
 * damit **ausdruecklich eine Schaetzung**, kein abgerechneter Umsatz
 * (Entscheidung D11, so auch im Produkt bezeichnet).
 */
final class Wartelistenkennzahlen
{
    /**
     * @return array<string, mixed>
     */
    public function fuerMonat(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $von = $jetzt->startOfMonth();
        $bis = $jetzt->endOfMonth();

        $angebote = WaitlistOffer::query()->whereBetween('created_at', [$von, $bis])->get();

        $beantwortet = $angebote->whereIn('status', [
            WaitlistOfferStatus::Accepted,
            WaitlistOfferStatus::Declined,
            WaitlistOfferStatus::Expired,
        ]);

        $angenommen = $angebote->where('status', WaitlistOfferStatus::Accepted);

        return [
            'zeitraum' => $jetzt->format('Y-m'),
            'angebote' => $angebote->count(),
            'angenommen' => $angenommen->count(),

            // Ohne beantwortete Angebote gibt es keine Quote -- und null
            // Prozent waere eine Aussage, die niemand geprueft hat.
            'annahmequote' => $beantwortet->count() === 0
                ? null
                : round($angenommen->count() / $beantwortet->count(), 3),

            'kosten_micros' => (int) $angebote->sum('cost_micros'),
            'wert_cents' => $this->wert($angenommen->pluck('appointment_id')->filter()->all()),
            'minuten_bis_zusage' => $this->minutenBisZusage($angenommen->all()),
        ];
    }

    /**
     * Der geschaetzte Wert der gefuellten Luecken.
     *
     * @param  array<int, mixed>  $terminschluessel
     */
    private function wert(array $terminschluessel): int
    {
        if ($terminschluessel === []) {
            return 0;
        }

        return (int) Appointment::query()
            ->whereIn('id', $terminschluessel)
            ->with('appointmentType.treatment')
            ->get()
            ->sum(fn (Appointment $termin): int => (int) $termin->appointmentType->treatment?->avg_revenue_cents);
    }

    /**
     * Wie lange es im Schnitt vom Angebot bis zur Zusage dauerte.
     *
     * Die Spezifikation nennt "von der Absage bis zur Neubelegung". Zwischen
     * Absage und Angebot liegt ein Auftrag auf der Queue, also Sekunden --
     * gemessen wird ab dem Angebot, und das ist die ehrlichere Zahl.
     *
     * @param  array<int, WaitlistOffer>  $angenommen
     */
    private function minutenBisZusage(array $angenommen): ?int
    {
        $dauern = [];

        foreach ($angenommen as $angebot) {
            if ($angebot->answered_at instanceof CarbonImmutable && $angebot->created_at instanceof CarbonImmutable) {
                $dauern[] = $angebot->created_at->diffInMinutes($angebot->answered_at, absolute: true);
            }
        }

        return $dauern === [] ? null : (int) round(array_sum($dauern) / count($dauern));
    }
}
