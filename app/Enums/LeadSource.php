<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Anfrage hereinkam.
 *
 * **Nicht die Attribution.** Diese Angabe sagt, ueber welchen Weg jemand sich
 * gemeldet hat; woher er kam -- Kampagne, Anzeige, Suchbegriff -- entsteht in
 * WP-32 und ersetzt sie nicht, sondern ergaenzt sie.
 *
 * Sie ist trotzdem noetig, und `docs/fachlogik/attribution.md` sagt warum:
 * "Ein Teil der Anzeigen-Leads ruft an oder kommt vorbei. Ohne dieses Feld
 * fehlen diese Buchungen und der ROAS sieht schlechter aus, als er ist."
 */
enum LeadSource: string
{
    case BookingPage = 'booking_page';

    case Phone = 'phone';

    case WalkIn = 'walk_in';

    case Message = 'message';

    case Referral = 'referral';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BookingPage => 'Buchungsseite',
            self::Phone => 'Telefon',
            self::WalkIn => 'Vor Ort',
            self::Message => 'Nachricht',
            self::Referral => 'Empfehlung',
            self::Other => 'Sonstiges',
        };
    }
}
