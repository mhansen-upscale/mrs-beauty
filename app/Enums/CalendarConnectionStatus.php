<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie es um eine Kalenderverbindung steht.
 *
 * R4 aus docs/integrationen/kalender.md: stille Ausfaelle sind der Normalfall.
 * Abonnements laufen ab, Token werden entzogen, Kalender verschwinden -- und
 * nichts davon erzeugt von sich aus eine Meldung. Dieser Zustand ist die
 * Stelle, an der ein Ausfall sichtbar wird, und er steht **im Produkt**, nicht
 * nur im Log.
 */
enum CalendarConnectionStatus: string
{
    case Active = 'active';

    /** Zugang abgelaufen oder entzogen. Eine Neuverbindung ist noetig. */
    case Expired = 'expired';

    /** Vom Team getrennt. Bleibt bis zum Aufraeumen stehen. */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Verbunden',
            self::Expired => 'Unterbrochen',
            self::Revoked => 'Getrennt',
        };
    }

    /** Wird noch abgeglichen? */
    public function istAktiv(): bool
    {
        return $this === self::Active;
    }

    /** Verlangt der Zustand eine Handlung des Teams? */
    public function brauchtAufmerksamkeit(): bool
    {
        return $this === self::Expired;
    }
}
