<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Worum es in einer eingehenden Nachricht geht.
 *
 * Die Liste stammt aus docs/fachlogik/agent.md, Schritt 3, und ist
 * abschliessend. Sie ist keine Geschmacksfrage: an ihr haengt die harte
 * Weiche aus Schritt 4 -- `medical_question` und `complaint` fuehren zur
 * Eskalation, und zwar vor jeder Textgenerierung.
 */
enum AgentIntent: string
{
    case BookingRequest = 'booking_request';

    case RescheduleRequest = 'reschedule_request';

    case CancelRequest = 'cancel_request';

    case PriceQuestion = 'price_question';

    case GeneralQuestion = 'general_question';

    /** Eignung, Risiken, Wirkstoffe, Nachsorge, Beschwerden nach einem Eingriff. */
    case MedicalQuestion = 'medical_question';

    case Complaint = 'complaint';

    case Spam = 'spam';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BookingRequest => 'Terminwunsch',
            self::RescheduleRequest => 'Verschieben',
            self::CancelRequest => 'Absage',
            self::PriceQuestion => 'Preisfrage',
            self::GeneralQuestion => 'Allgemeine Frage',
            self::MedicalQuestion => 'Medizinische Frage',
            self::Complaint => 'Beschwerde',
            self::Spam => 'Spam',
            self::Other => 'Sonstiges',
        };
    }

    /**
     * Darf zu dieser Absicht ueberhaupt ein Text entstehen?
     *
     * **Nein heisst hier: auch kein Entwurf.** Was im Eingabefeld steht, wird
     * irgendwann abgeschickt -- ein Vorschlag zu einer medizinischen Frage
     * waere schon als Entwurf falsch (Regel 6, Entscheidung G3).
     *
     * Die harte Weiche aus Schritt 4 kommt in WP-23 dazu: Wortstammsuche fuer
     * Komplikationssignale, Bildanhaenge, Alarm. Dieses `match` ist nicht sie,
     * sondern die Untergrenze.
     */
    public function darfVorschlagen(): bool
    {
        return match ($this) {
            self::MedicalQuestion, self::Complaint, self::Spam => false,
            default => true,
        };
    }

    /** Fuehrt diese Absicht unmittelbar zu einem Menschen? */
    public function eskaliert(): bool
    {
        return $this === self::MedicalQuestion || $this === self::Complaint;
    }
}
