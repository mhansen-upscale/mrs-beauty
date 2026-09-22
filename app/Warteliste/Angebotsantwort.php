<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Agent\Buchung\Antwortdeutung;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Kanaele\Nachrichtenversand;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Message;
use App\Models\Practitioner;
use App\Models\SlotHold;
use App\Models\WaitlistOffer;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;

/**
 * Was aus einer Antwort auf ein Angebot wird.
 *
 * **Ein offenes Angebot geht der Einordnung vor.** Wer auf "Es ist ein Termin
 * frei geworden" mit "Ja" antwortet, meint dieses Angebot -- und nicht eine
 * neue Terminanfrage, die der Agent klassifizieren muesste.
 *
 * Gedeutet wird ohne Modell (dieselbe Wortliste wie im Buchungsdialog): eine
 * Zustimmung, die nicht dasteht, wird nicht angenommen.
 */
final class Angebotsantwort
{
    public function __construct(
        private readonly Antwortdeutung $deutung,
        private readonly SlotHalter $halter,
        private readonly Terminplaner $planer,
        private readonly Nachrichtenversand $versand,
        private readonly Angebotstexte $texte,
    ) {}

    /**
     * Prueft, ob diese Nachricht eine Antwort auf ein Angebot ist.
     *
     * @return bool Ob die Nachricht damit erledigt ist
     */
    public function pruefe(Message $nachricht, ?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();

        $kontakt = $nachricht->conversation->contact;

        if ($kontakt === null) {
            return false;
        }

        $angebot = WaitlistOffer::query()
            ->whereHas('entry', fn ($abfrage) => $abfrage->where('contact_id', $kontakt->getKey()))
            ->where('status', WaitlistOfferStatus::Pending->value)
            ->orderByDesc('created_at')
            ->first();

        if (! $angebot instanceof WaitlistOffer) {
            return false;
        }

        if ($this->deutung->ablehnung($nachricht)) {
            $this->lehneAb($angebot, $jetzt);

            return true;
        }

        if (! $this->deutung->zustimmung($nachricht)) {
            // Weder ja noch nein: das Angebot bleibt offen, und die Nachricht
            // geht ihren gewoehnlichen Weg.
            return false;
        }

        return $this->nimmAn($angebot, $nachricht, $jetzt);
    }

    /** Annahme -- oder die Meldung, dass es zu spaet ist. */
    public function nimmAn(WaitlistOffer $angebot, Message $nachricht, ?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();

        if (! $angebot->giltNoch($jetzt)) {
            // Grenzfall aus der Spezifikation: klare Meldung, kein Fehler --
            // und der Eintrag bleibt aktiv.
            $this->laufAb($angebot, $jetzt);
            $this->antworte($nachricht, $this->texte->zuSpaet());

            return true;
        }

        $slot = $this->slot($angebot);

        if (! $slot instanceof Slotvorschlag) {
            return false;
        }

        // **Ausloeser 3 ist ausdruecklich kein automatischer Vorgang.** Der
        // Slot ist noch belegt; der wackelige Termin wird erst nach
        // Rueckfrage beim Team aufgeloest.
        if ($angebot->trigger === WaitlistTrigger::NoResponse) {
            $angebot->status = WaitlistOfferStatus::Accepted;
            $angebot->answered_at = $jetzt;
            $angebot->save();

            $this->antworte($nachricht, $this->texte->geprueftWird($slot));

            return true;
        }

        $hold = $angebot->hold;

        if (! $hold instanceof SlotHold || ! $hold->giltNoch()) {
            $this->laufAb($angebot, $jetzt);
            $this->antworte($nachricht, $this->texte->zuSpaet());

            return true;
        }

        $eintrag = $angebot->entry;

        $termin = $this->planer->loeseEin(
            hold: $hold,
            vorschlag: $slot,
            kontakt: $eintrag->contact,
            kanal: BookingChannel::Waitlist,
            status: AppointmentStatus::Confirmed,
            einwilligung: $jetzt,
        );

        $angebot->status = WaitlistOfferStatus::Accepted;
        $angebot->answered_at = $jetzt;
        $angebot->appointment_id = $termin->getKey();
        $angebot->slot_hold_id = null;
        $angebot->save();

        $eintrag->status = WaitlistStatus::Booked;
        $eintrag->save();

        // Alle anderen offenen Angebote fuer diesen Slot sind ueberholt.
        $this->ueberhole($angebot);

        $this->antworte($nachricht, $this->texte->angenommen($slot));

        return true;
    }

    public function lehneAb(WaitlistOffer $angebot, ?CarbonImmutable $jetzt = null): void
    {
        $this->schliesse($angebot, WaitlistOfferStatus::Declined, $jetzt);
    }

    /**
     * Raeumt ein abgelaufenes Angebot ab -- Hold frei, Eintrag wieder aktiv.
     */
    public function laufAb(WaitlistOffer $angebot, ?CarbonImmutable $jetzt = null): void
    {
        $this->schliesse($angebot, WaitlistOfferStatus::Expired, $jetzt);
    }

    private function schliesse(WaitlistOffer $angebot, WaitlistOfferStatus $zustand, ?CarbonImmutable $jetzt): void
    {
        $jetzt ??= CarbonImmutable::now();
        $hold = $angebot->hold;

        if ($hold instanceof SlotHold && $hold->giltNoch()) {
            $this->halter->gibFrei($hold);
        }

        $angebot->status = $zustand;
        $angebot->answered_at = $jetzt;
        $angebot->slot_hold_id = null;
        $angebot->save();

        $eintrag = $angebot->entry;

        // Zurueck in die Warteschlange -- ausser der Eintrag ist inzwischen
        // abgelaufen. Dann bleibt er abgelaufen: das Angebot galt bis zu
        // seinem Ende, der Eintrag nicht.
        if ($eintrag->status === WaitlistStatus::Offered) {
            $eintrag->status = $eintrag->expires_at->greaterThan($jetzt)
                ? WaitlistStatus::Active
                : WaitlistStatus::Expired;
            $eintrag->save();
        }
    }

    private function ueberhole(WaitlistOffer $angenommen): void
    {
        WaitlistOffer::query()
            ->where('starts_at', $angenommen->starts_at)
            ->where('practitioner_id', $angenommen->practitioner_id)
            ->where('status', WaitlistOfferStatus::Pending->value)
            ->whereKeyNot($angenommen->getKey())
            ->get()
            ->each(function (WaitlistOffer $anderes): void {
                $this->schliesse($anderes, WaitlistOfferStatus::Superseded, null);
            });
    }

    private function antworte(Message $nachricht, string $text): void
    {
        $this->versand->stelleEin($nachricht->conversation, $text);
    }

    /**
     * Der Slot, wie er angeboten wurde -- aus dem Angebot, nicht aus dem
     * Hold: das Angebot ueberlebt ihn.
     */
    public function slot(WaitlistOffer $angebot): ?Slotvorschlag
    {
        $art = AppointmentType::query()->whereKey($angebot->appointment_type_id)->first();
        $behandler = Practitioner::query()->whereKey($angebot->practitioner_id)->first();
        $standort = Location::query()->whereKey($angebot->location_id)->first();

        if (! $art instanceof AppointmentType
            || ! $behandler instanceof Practitioner
            || ! $standort instanceof Location) {
            return null;
        }

        return new Slotvorschlag(
            art: $art,
            behandler: $behandler,
            standort: $standort,
            blockedFrom: $angebot->blocked_from,
            blockedUntil: $angebot->blocked_until,
            startsAt: $angebot->starts_at,
            endsAt: $angebot->ends_at,
        );
    }
}
