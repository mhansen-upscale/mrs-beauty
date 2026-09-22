<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie Meta eine Nachricht abrechnet.
 *
 * **Wird nie geschaetzt.** `docs/integrationen/meta.md` ist an der Stelle
 * ausdruecklich: die Kategorie kommt je Nachricht aus der Antwort der API.
 * Eine geschaetzte Kategorie wird zu einer geschaetzten Rechnung, und die
 * stimmt nie -- ab 01.10.2026 auch nicht mehr innerhalb des 24-Stunden-
 * Fensters, das bis dahin kostenlos war.
 */
enum MessageCostCategory: string
{
    case Service = 'service';

    case Utility = 'utility';

    case Marketing = 'marketing';

    case Authentication = 'authentication';

    /** Kostenlos oder ausserhalb der Abrechnung -- etwa E-Mail. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Utility => 'Utility',
            self::Marketing => 'Marketing',
            self::Authentication => 'Authentifizierung',
            self::None => 'Ohne Kosten',
        };
    }

    /** Faellt fuer diese Kategorie Geld an? */
    public function kostetGeld(): bool
    {
        return $this !== self::None;
    }
}
