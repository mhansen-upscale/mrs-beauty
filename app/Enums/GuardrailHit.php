<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Welche Schutzregel gegriffen hat.
 *
 * Die Liste folgt docs/fachlogik/agent.md, Schritt 4 (harte Weiche), Schritt 5
 * (Konfidenz) und Schritt 7 (Nachpruefung). Sie steht im Protokoll und in der
 * Inbox -- **einer Praxis muss sich erklaeren lassen, warum ihr Agent
 * geschwiegen hat.**
 */
enum GuardrailHit: string
{
    /* Schritt 4 -- harte Weiche, vor jeder Textgenerierung */

    case MedicalQuestion = 'medical_question';

    case Complaint = 'complaint';

    /** Wortstammsuche, nicht Klassifikator. Nicht ueberstimmbar. */
    case Complication = 'complication';

    case Spam = 'spam';

    case ImageAttachment = 'image_attachment';

    /* Schritt 5 -- Konfidenz und Gespraechslaenge */

    case LowConfidence = 'low_confidence';

    case TooManyAutoReplies = 'too_many_auto_replies';

    /* Not-Aus */

    case KillSwitch = 'kill_switch';

    case TenantDisabled = 'tenant_disabled';

    case Paused = 'paused';

    /** Das Kontingent des Monats ist aufgebraucht (Entscheidung G11). */
    case BudgetExhausted = 'budget_exhausted';

    /* Schritt 7 -- Nachpruefung der erzeugten Antwort */

    case PriceOutsideCatalog = 'price_outside_catalog';

    case Discount = 'discount';

    case MedicalStatement = 'medical_statement';

    case Promise = 'promise';

    case UnknownTreatment = 'unknown_treatment';

    case ForeignLanguage = 'foreign_language';

    public function label(): string
    {
        return match ($this) {
            self::MedicalQuestion => 'Medizinische Frage',
            self::Complaint => 'Beschwerde',
            self::Complication => 'Komplikationssignal',
            self::Spam => 'Spam',
            self::ImageAttachment => 'Bildanhang',
            self::LowConfidence => 'Zu unsicher',
            self::TooManyAutoReplies => 'Zu viele automatische Antworten',
            self::KillSwitch => 'Not-Aus der Installation',
            self::TenantDisabled => 'Assistent abgeschaltet',
            self::Paused => 'Assistent pausiert',
            self::BudgetExhausted => 'Kontingent aufgebraucht',
            self::PriceOutsideCatalog => 'Preis außerhalb des Katalogs',
            self::Discount => 'Rabatt oder Aktion',
            self::MedicalStatement => 'Medizinische Aussage',
            self::Promise => 'Zusage',
            self::UnknownTreatment => 'Behandlung außerhalb des Katalogs',
            self::ForeignLanguage => 'Nicht auf Deutsch',
        };
    }

    /**
     * Loest diese Regel einen Alarm aus?
     *
     * **Nur das Komplikationssignal** (Entscheidung G4). Ein Alarm, der bei
     * jeder Preisfrage losgeht, wird nach einer Woche weggeklickt -- und geht
     * dann auch bei der Schwellung nicht mehr an.
     */
    public function alarmiert(): bool
    {
        return $this === self::Complication;
    }

    /**
     * Haelt diese Regel den Agenten aus dem Gespraech heraus, bis ein Mensch
     * ihn wieder hereinlaesst?
     *
     * Nach einer Komplikation, einer Beschwerde oder einem Bild soll er nicht
     * bei der naechsten Nachricht wieder mitreden, als waere nichts gewesen.
     */
    public function pausiert(): bool
    {
        return match ($this) {
            self::Complication, self::Complaint, self::ImageAttachment => true,
            default => false,
        };
    }
}
