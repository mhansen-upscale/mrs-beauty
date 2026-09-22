<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo der Buchungsdialog gerade steht (docs/fachlogik/agent.md, Schritt 6).
 *
 * **An der Konversation, nicht an der Nachricht.** Ein Mensch schreibt in
 * drei Nachrichten, was er will, und erwartet, dass das Gegenueber sich
 * erinnert.
 */
enum BookingState: string
{
    case Start = 'start';

    case TreatmentKlaeren = 'treatment_klaeren';

    /** Nur bei mehreren Standorten. */
    case StandortKlaeren = 'standort_klaeren';

    /** Nur, wenn der Kontakt danach fragt. */
    case BehandlerKlaeren = 'behandler_klaeren';

    case SlotsVorschlagen = 'slots_vorschlagen';

    /** Name und fehlender Kontaktweg. */
    case DatenErheben = 'daten_erheben';

    case Einwilligung = 'einwilligung';

    case Bestaetigen = 'bestaetigen';

    case Gebucht = 'gebucht';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Beginn',
            self::TreatmentKlaeren => 'Behandlung klären',
            self::StandortKlaeren => 'Standort klären',
            self::BehandlerKlaeren => 'Behandler klären',
            self::SlotsVorschlagen => 'Termine vorschlagen',
            self::DatenErheben => 'Daten erheben',
            self::Einwilligung => 'Einwilligung',
            self::Bestaetigen => 'Bestätigen',
            self::Gebucht => 'Gebucht',
        };
    }

    /** Ist der Vorgang abgeschlossen? */
    public function abgeschlossen(): bool
    {
        return $this === self::Gebucht;
    }

    /**
     * Haelt dieser Zustand einen Slot?
     *
     * Ab `slots_vorschlagen` liegt eine Reservierung -- und die muss beim
     * Verlassen des Dialogs freigegeben werden, sonst blockiert sie einen
     * echten Termin.
     */
    public function haeltSlot(): bool
    {
        return match ($this) {
            self::SlotsVorschlagen, self::DatenErheben, self::Einwilligung, self::Bestaetigen => true,
            default => false,
        };
    }
}
