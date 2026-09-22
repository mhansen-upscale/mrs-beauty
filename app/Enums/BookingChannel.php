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
}
