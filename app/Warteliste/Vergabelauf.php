<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Enums\HoldPurpose;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\Organization;
use App\Models\SlotHold;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Die gestaffelte Vergabe (docs/fachlogik/warteliste.md).
 *
 * **Einer nach dem anderen, nicht alle gleichzeitig.** Ein Rundruf erzeugt
 * mehrere Zusagen fuer einen Slot, von denen nur eine gewinnt -- die anderen
 * bekommen eine Absage auf ein Angebot, das ihnen geschickt wurde. Das ist
 * schlechter als gar kein Angebot.
 *
 * **Jedes Angebot haelt den Slot** (ausser bei einem wackeligen Termin, wo er
 * noch belegt ist). Ohne Hold buchte waehrend des Angebotszeitraums jemand
 * ueber die Buchungsseite denselben Slot, und die Interessentin bekaeme auf
 * ihre Zusage eine Fehlermeldung.
 */
final class Vergabelauf
{
    public function __construct(
        private readonly Kandidatensuche $suche,
        private readonly SlotHalter $halter,
        private readonly Nachrichtenversand $versand,
        private readonly Konversationen $konversationen,
        private readonly Angebotstexte $texte,
        private readonly TenantContext $mandant,
    ) {}

    /**
     * Bietet den Slot dem naechsten passenden Eintrag an.
     *
     * Gibt das Angebot zurueck -- oder null, wenn niemand in Frage kommt.
     * Dann bleibt der Slot offen; das ist kein Fehlerfall, sondern der
     * haeufigste Ausgang einer spaeten Absage.
     */
    public function biete(
        Slotvorschlag $slot,
        WaitlistTrigger $ausloeser = WaitlistTrigger::Cancellation,
        ?CarbonImmutable $jetzt = null,
    ): ?WaitlistOffer {
        $jetzt ??= CarbonImmutable::now();

        $ausgeschlossen = $this->bereitsGefragt($slot);

        if (count($ausgeschlossen) >= (int) config('mrs.waitlist.max_rounds', 5)) {
            // Nach so vielen erfolglosen Runden bleibt der Slot offen. Wer
            // weiter fragt, verbrennt den Kanal.
            return null;
        }

        $kandidat = $this->suche->naechster($slot, $ausgeschlossen, $jetzt);

        if (! $kandidat instanceof WaitlistEntry) {
            return null;
        }

        $hold = $ausloeser->haeltSlot() ? $this->halte($slot, $jetzt) : null;

        if ($ausloeser->haeltSlot() && ! $hold instanceof SlotHold) {
            // Der Slot ist zwischen Auswahl und Hold weggegangen.
            return null;
        }

        $angebot = $this->lege($kandidat, $slot, $ausloeser, $hold, $jetzt);

        if (! $angebot instanceof WaitlistOffer) {
            // K9 als Datenbankregel: dieser Eintrag hat schon ein offenes
            // Angebot. Den Hold nicht liegenlassen.
            if ($hold instanceof SlotHold) {
                $this->halter->gibFrei($hold);
            }

            return null;
        }

        $this->verschicke($kandidat, $slot, $angebot);

        $kandidat->status = WaitlistStatus::Offered;
        $kandidat->offers_sent_count++;
        $kandidat->last_offered_at = $jetzt;
        $kandidat->save();

        return $angebot;
    }

    /**
     * Wem dieser Slot schon angeboten wurde (K12 und die Rundenzaehlung).
     *
     * @return list<string>
     */
    private function bereitsGefragt(Slotvorschlag $slot): array
    {
        /** @var list<string> */
        return WaitlistOffer::query()
            ->where('starts_at', $slot->startsAt)
            ->where('practitioner_id', $slot->behandler->getKey())
            ->pluck('waitlist_entry_id')
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();
    }

    private function halte(Slotvorschlag $slot, CarbonImmutable $jetzt): ?SlotHold
    {
        try {
            return $this->halter->halte($slot, HoldPurpose::WaitlistOffer, $jetzt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function lege(
        WaitlistEntry $eintrag,
        Slotvorschlag $slot,
        WaitlistTrigger $ausloeser,
        ?SlotHold $hold,
        CarbonImmutable $jetzt,
    ): ?WaitlistOffer {
        $minuten = (int) config('mrs.waitlist.offer_ttl_minutes', 30);

        try {
            $angebot = new WaitlistOffer;
            $angebot->waitlist_entry_id = $eintrag->getKey();
            $angebot->entry_key = $eintrag->getKey();
            $angebot->slot_hold_id = $hold?->getKey();
            $angebot->practitioner_id = $slot->behandler->getKey();
            $angebot->location_id = $slot->standort->getKey();
            $angebot->appointment_type_id = $slot->art->getKey();
            $angebot->status = WaitlistOfferStatus::Pending;
            $angebot->trigger = $ausloeser;
            $angebot->starts_at = $slot->startsAt;
            $angebot->ends_at = $slot->endsAt;
            $angebot->blocked_from = $slot->blockedFrom;
            $angebot->blocked_until = $slot->blockedUntil;
            $angebot->expires_at = $jetzt->addMinutes($minuten);
            $angebot->save();

            return $angebot;
        } catch (QueryException $ausnahme) {
            if (str_contains($ausnahme->getMessage(), 'angebot_offen_unique')) {
                return null;
            }

            throw $ausnahme;
        }
    }

    /**
     * Schickt das Angebot ueber den Kanal, fuer den eine Einwilligung
     * vorliegt (K11).
     */
    private function verschicke(WaitlistEntry $eintrag, Slotvorschlag $slot, WaitlistOffer $angebot): void
    {
        $identitaet = $this->suche->kanal($eintrag);

        if ($identitaet === null) {
            return;
        }

        $organisation = $this->mandant->current();
        $praxisname = $organisation instanceof Organization ? $organisation->name : 'Ihrer Praxis';

        $gespraech = $this->konversationen->fuer($identitaet);

        // **Ueber dieselbe Warteschlange wie jede andere Nachricht** (Regel
        // 4). Der Idempotenzschluessel haengt am Angebot: ein zweiter Lauf
        // desselben Vergabelaufs verschickt nicht zweimal.
        $this->versand->stelleEin(
            $gespraech,
            $this->texte->angebot($slot, $praxisname),
            idempotenz: 'warteliste-'.Uuid::toString($angebot->getKey()),
        );
    }
}
