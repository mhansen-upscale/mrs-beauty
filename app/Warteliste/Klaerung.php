<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\Appointment;
use App\Models\WaitlistOffer;
use App\Support\Uuid;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Der wackelige Termin (docs/fachlogik/warteliste.md, Ausloeser 3).
 *
 * Keine Reaktion auf die Erinnerung: der Slot wird parallel angeboten, ohne
 * den bestehenden Termin anzutasten. **Nimmt jemand an, entscheidet ein
 * Mensch** -- "ausdruecklich kein automatischer Vorgang". Diese Klasse ist
 * das, was ihn daran erinnert, und das, was seine Entscheidung ausfuehrt.
 *
 * Bis zum 26.09.2026 fehlte sie: die Wartende bekam "wir klaeren das", und
 * dann klaerte es niemand.
 */
final class Klaerung
{
    public function __construct(
        private readonly Terminplaner $planer,
        private readonly Angebotsantwort $antwort,
        private readonly Angebotstexte $texte,
        private readonly Kandidatensuche $suche,
        private readonly Konversationen $konversationen,
        private readonly Nachrichtenversand $versand,
    ) {}

    /**
     * Zusagen, ueber die noch niemand entschieden hat.
     *
     * @return Collection<int, WaitlistOffer>
     */
    public function offene(): Collection
    {
        return WaitlistOffer::query()
            ->where('trigger', WaitlistTrigger::NoResponse->value)
            ->where('status', WaitlistOfferStatus::Accepted->value)
            ->whereNull('appointment_id')
            ->with('entry.contact')
            ->orderBy('starts_at')
            ->get();
    }

    /** Der Termin, der auf die Erinnerung nicht reagiert hat -- falls er noch steht. */
    public function wackeligerTermin(WaitlistOffer $angebot): ?Appointment
    {
        return Appointment::query()
            ->where('practitioner_id', $angebot->practitioner_id)
            ->where('starts_at', $angebot->starts_at)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->with('contact')
            ->first();
    }

    /**
     * Der Slot geht an die Wartende: der bestehende Termin wird abgesagt, ihr
     * Termin angelegt -- **in einer Transaktion**. Scheitert die Buchung,
     * steht die Absage nicht.
     *
     * @throws RuntimeException wenn die Zusage schon geklaert ist
     */
    public function uebergib(WaitlistOffer $angebot, ?CarbonImmutable $jetzt = null): Appointment
    {
        $jetzt ??= CarbonImmutable::now();

        [$termin, $slot] = DB::transaction(function () use ($angebot, $jetzt): array {
            $angebot = $this->sperre($angebot);
            $slot = $this->slot($angebot);

            $wackelig = $this->wackeligerTermin($angebot);

            if ($wackelig instanceof Appointment) {
                // Die Praxis sagt ab, nicht die Person: sie hat nicht reagiert,
                // und das Team hat entschieden.
                $this->planer->sageAb($wackelig, CancellationReason::Practice, $jetzt);
            }

            $eintrag = $angebot->entry;

            $termin = $this->planer->buche(
                vorschlag: $slot,
                kontakt: $eintrag->contact,
                kanal: BookingChannel::Waitlist,
                status: AppointmentStatus::Confirmed,
                jetzt: $jetzt,
            );

            $angebot->appointment_id = $termin->getKey();
            $angebot->save();

            $eintrag->status = WaitlistStatus::Booked;
            $eintrag->save();

            return [$termin, $slot];
        });

        $this->schreibe($angebot, $this->texte->angenommen($slot));

        return $termin;
    }

    /**
     * Der bestehende Termin bleibt. Die Wartende erfaehrt es -- und wartet
     * weiter: die Absage gilt diesem Slot, nicht ihr.
     *
     * @throws RuntimeException wenn die Zusage schon geklaert ist
     */
    public function behalte(WaitlistOffer $angebot, ?CarbonImmutable $jetzt = null): void
    {
        $jetzt ??= CarbonImmutable::now();

        $slot = DB::transaction(function () use ($angebot, $jetzt): Slotvorschlag {
            $angebot = $this->sperre($angebot);

            $angebot->status = WaitlistOfferStatus::Superseded;
            $angebot->save();

            $eintrag = $angebot->entry;

            if ($eintrag->status === WaitlistStatus::Offered) {
                $eintrag->status = $eintrag->expires_at->greaterThan($jetzt) ? WaitlistStatus::Active : WaitlistStatus::Expired;
                $eintrag->save();
            }

            return $this->slot($angebot);
        });

        $this->schreibe($angebot, $this->texte->dochVergeben($slot));
    }

    /**
     * Sperrt die Zeile und prueft, ob noch etwas zu klaeren ist. Zwei
     * Menschen am Empfang, die gleichzeitig entscheiden, entscheiden nicht
     * zweimal.
     */
    private function sperre(WaitlistOffer $angebot): WaitlistOffer
    {
        $gesperrt = WaitlistOffer::query()->whereKey($angebot->getKey())->lockForUpdate()->first();

        if (! $gesperrt instanceof WaitlistOffer
            || $gesperrt->trigger !== WaitlistTrigger::NoResponse
            || $gesperrt->status !== WaitlistOfferStatus::Accepted
            || $gesperrt->appointment_id !== null) {
            throw new RuntimeException('Diese Zusage ist schon geklärt.');
        }

        return $gesperrt;
    }

    private function slot(WaitlistOffer $angebot): Slotvorschlag
    {
        return $this->antwort->slot($angebot)
            ?? throw new RuntimeException('Terminart, Behandler oder Standort gibt es nicht mehr.');
    }

    /**
     * Ueber den Kanal, fuer den die Einwilligung vorliegt (K11) -- denselben,
     * ueber den das Angebot kam.
     */
    private function schreibe(WaitlistOffer $angebot, string $text): void
    {
        $identitaet = $this->suche->kanal($angebot->entry);

        if ($identitaet === null) {
            return;
        }

        $this->versand->stelleEin(
            $this->konversationen->fuer($identitaet),
            $text,
            idempotenz: 'klaerung-'.Uuid::toString($angebot->getKey()),
        );
    }
}
