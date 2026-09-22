<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie ein Termin zustande kam.
 *
 * Nicht dasselbe wie die Attribution: der Kanal sagt, **wer** gebucht hat,
 * die Attribution sagt, **woher** die Anfrage kam. WP-32 braucht beides.
 */
enum BookingChannel: string
{
    /** Der Empfang hat ihn eingetragen (WP-11). */
    case Internal = 'internal';

    /** Die oeffentliche Buchungsseite (WP-12). */
    case Public = 'public';

    /** Der Agent hat automatisch gebucht (WP-24). */
    case Agent = 'agent';

    /** Ein angenommenes Wartelistenangebot (WP-25). */
    case Waitlist = 'waitlist';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Vom Empfang',
            self::Public => 'Buchungsseite',
            self::Agent => 'Assistent',
            self::Waitlist => 'Warteliste',
        };
    }

    /**
     * Die grobe Herkunft der Anfrage (WP-17).
     *
     * **Vom Empfang heisst "unbestimmt", nicht "Telefon".** Ein Teil dieser
     * Buchungen kommt aus einem Anruf, ein Teil von der Tuer, ein Teil aus
     * einer Anzeige -- WP-32 macht die Quelle beim Anlegen zum Pflichtfeld
     * und weiss es dann. Bis dahin waere jede Zuordnung erfunden, und eine
     * erfundene Quelle ist schlechter als keine.
     */
    public function alsLeadquelle(): LeadSource
    {
        return match ($this) {
            self::Internal => LeadSource::Other,
            self::Public => LeadSource::BookingPage,
            self::Agent, self::Waitlist => LeadSource::Message,
        };
    }
}
