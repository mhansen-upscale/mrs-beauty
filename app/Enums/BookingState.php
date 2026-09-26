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

    /**
     * Nichts frei -- die Warteliste ist angeboten (Testfall 14). Die Frage ist
     * zugleich die nach dem Kanal: ohne Einwilligung bekaeme der Eintrag nie
     * ein Angebot (K11).
     */
    case WartelisteAnbieten = 'warteliste_anbieten';

    case AufWarteliste = 'auf_warteliste';

    /*
     * Ein Termin, der schon steht (offen seit WP-24): absagen oder
     * verschieben -- immer mit ausdruecklicher Bestaetigung.
     */
    case AbsageBestaetigen = 'absage_bestaetigen';

    case VerschiebenVorschlagen = 'verschieben_vorschlagen';

    /** Der neue Slot ist gehalten, der alte noch belegt. */
    case VerschiebenBestaetigen = 'verschieben_bestaetigen';

    /** Abgesagt, verschoben -- oder bewusst stehen gelassen. */
    case Geaendert = 'geaendert';

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
            self::WartelisteAnbieten => 'Warteliste anbieten',
            self::AufWarteliste => 'Auf der Warteliste',
            self::AbsageBestaetigen => 'Absage bestätigen',
            self::VerschiebenVorschlagen => 'Verschieben: Termine vorschlagen',
            self::VerschiebenBestaetigen => 'Verschieben bestätigen',
            self::Geaendert => 'Termin geändert',
        };
    }

    /** Ist der Vorgang abgeschlossen? */
    public function abgeschlossen(): bool
    {
        return in_array($this, [self::Gebucht, self::AufWarteliste, self::Geaendert], true);
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
            self::SlotsVorschlagen, self::DatenErheben, self::Einwilligung, self::Bestaetigen, self::VerschiebenBestaetigen => true,
            default => false,
        };
    }

    /** Geht es gerade um einen Termin, der schon steht? */
    public function aendertTermin(): bool
    {
        return in_array($this, [self::AbsageBestaetigen, self::VerschiebenVorschlagen, self::VerschiebenBestaetigen], true);
    }
}
