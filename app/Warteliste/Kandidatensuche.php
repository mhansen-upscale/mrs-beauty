<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Datenschutz\Einwilligungen;
use App\Enums\ConsentType;
use App\Enums\WaitlistStatus;
use App\Models\ChannelIdentity;
use App\Models\Organization;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wer fuer diesen Slot in Frage kommt (docs/fachlogik/warteliste.md, K1..K12).
 *
 * **Deterministisch.** Rangfolge: Prioritaet absteigend, dann wer laenger
 * wartet, dann der Schluessel. Keine Zufallsauswahl und keine Gewichtung nach
 * Behandlungswert -- letzteres waere naheliegend, ist aber gegenueber der
 * Interessentin nicht vermittelbar und gehoert nicht in ein Produkt, das
 * Praxen im Aussenverhaeltnis vertritt.
 */
final class Kandidatensuche
{
    public function __construct(
        private readonly Einwilligungen $einwilligungen,
        private readonly TenantContext $mandant,
    ) {}

    /**
     * Der naechste Kandidat -- oder keiner.
     *
     * @param  list<string>  $ausgeschlossen  Schluessel bereits gefragter Eintraege
     */
    public function naechster(Slotvorschlag $slot, array $ausgeschlossen = [], ?CarbonImmutable $jetzt = null): ?WaitlistEntry
    {
        foreach ($this->infrage($slot, $ausgeschlossen, $jetzt) as $eintrag) {
            return $eintrag;
        }

        return null;
    }

    /**
     * Alle passenden Eintraege in der Rangfolge.
     *
     * @param  list<string>  $ausgeschlossen
     * @return list<WaitlistEntry>
     */
    public function infrage(Slotvorschlag $slot, array $ausgeschlossen = [], ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $ortszeit = $slot->ortszeit();

        $kandidaten = WaitlistEntry::query()
            // K1
            ->where('status', WaitlistStatus::Active->value)
            ->where('expires_at', '>', $jetzt)
            // K2
            ->where('appointment_type_id', $slot->art->getKey())
            // K4
            ->where(fn (Builder $q) => $q
                ->whereNull('practitioner_id')
                ->orWhere('practitioner_id', $slot->behandler->getKey()))
            // K5 -- das **lokale** Datum des Slots, nicht das in UTC.
            ->whereDate('earliest_date', '<=', $ortszeit->toDateString())
            ->whereDate('latest_date', '>=', $ortszeit->toDateString())
            ->when($ausgeschlossen !== [], fn (Builder $q) => $q->whereNotIn('id', $ausgeschlossen))
            ->with(['contact.channelIdentities'])
            // Rangfolge, ausgeschrieben.
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $passend = [];

        foreach ($kandidaten as $eintrag) {
            if ($this->passt($eintrag, $slot, $jetzt)) {
                $passend[] = $eintrag;
            }
        }

        return $passend;
    }

    /**
     * Die Bedingungen, die sich nicht in einer Abfrage ausdruecken lassen.
     */
    public function passt(WaitlistEntry $eintrag, Slotvorschlag $slot, ?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();
        $ortszeit = $slot->ortszeit();

        // K6
        if (! $eintrag->passtWochentag($ortszeit->dayOfWeekIso)) {
            return false;
        }

        // K7
        if (! $eintrag->passtUhrzeit($ortszeit->format('H:i'))) {
            return false;
        }

        // **K8 -- die Bedingung, an der die Warteliste steht und faellt.**
        if ($slot->startsAt->diffInHours($jetzt, absolute: true) < $eintrag->min_notice_hours
            || $slot->startsAt->lessThan($jetzt)) {
            return false;
        }

        // K3
        if (! $eintrag->all_locations && ! $eintrag->locations()->where('locations.id', $slot->standort->getKey())->exists()) {
            return false;
        }

        // K9 -- zusaetzlich zum Unique-Index: gar nicht erst anbieten.
        if ($eintrag->offers()->offen()->exists()) {
            return false;
        }

        // K12 -- derselbe Slot wird demselben Eintrag nie zweimal angeboten.
        if ($eintrag->offers()->where('starts_at', $slot->startsAt)->exists()) {
            return false;
        }

        // K10 -- die Monatsgrenze schuetzt den Kanal und die
        // Qualitaetsbewertung der Rufnummer.
        if ($this->grenzeErreicht($eintrag, $jetzt)) {
            return false;
        }

        // K11
        return $this->darfAngeschriebenWerden($eintrag);
    }

    /** K10: Angebote an diesen Kontakt im laufenden Kalendermonat. */
    public function grenzeErreicht(WaitlistEntry $eintrag, ?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();
        $grenze = $this->grenze();

        $anzahl = WaitlistOffer::query()
            ->whereHas('entry', fn (Builder $q) => $q->where('contact_id', $eintrag->contact_id))
            ->whereBetween('created_at', [$jetzt->startOfMonth(), $jetzt->endOfMonth()])
            ->count();

        return $anzahl >= $grenze;
    }

    /**
     * K11: eine gueltige Einwilligung fuer den bevorzugten Kanal.
     *
     * Ohne sie geht nichts hinaus -- ein Angebot ist eine Ansprache von
     * unserer Seite, und die verlangt eine Grundlage (WP-18).
     */
    public function darfAngeschriebenWerden(WaitlistEntry $eintrag): bool
    {
        return $this->kanal($eintrag) instanceof ChannelIdentity;
    }

    /** Ueber welchen Kanal das Angebot hinausgeht -- oder keinen. */
    public function kanal(WaitlistEntry $eintrag): ?ChannelIdentity
    {
        $kontakt = $eintrag->contact;

        foreach ($kontakt->channelIdentities as $identitaet) {
            if ($this->einwilligungen->darfSenden($identitaet, ConsentType::ServiceMessages)) {
                return $identitaet;
            }
        }

        return null;
    }

    /** Je Mandant aenderbar, mit Vorgabe aus der Konfiguration. */
    public function grenze(): int
    {
        $organisation = $this->mandant->current();

        $wert = $organisation instanceof Organization
            ? data_get($organisation->settings, 'waitlist_max_offers_per_contact_per_month')
            : null;

        return is_numeric($wert)
            ? max(1, (int) $wert)
            : (int) config('mrs.waitlist.max_offers_per_contact_per_month', 3);
    }
}
