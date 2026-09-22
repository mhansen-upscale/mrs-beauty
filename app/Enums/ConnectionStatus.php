<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie es um eine Verbindung zu einem Fremdsystem steht.
 *
 * Die Zustaende folgen der Fehlertabelle aus `docs/integrationen/meta.md`:
 * ein ungueltiges Token ist etwas anderes als eine fehlende Berechtigung, und
 * beides ist etwas anderes als ein voruebergehender Fehler. Wer alles gleich
 * behandelt und wiederholt, verdeckt, dass jemand etwas tun muss.
 *
 * **Der Zustand gehoert nicht dem Kanal.** Bis WP-26 hiess das hier
 * ChannelConnectionStatus -- und das Werbekonto, das kein Kanal ist, haette
 * einen "Kanalzustand" tragen muessen. Derselbe Fund wie bei
 * ChannelRawEvent in WP-20b: ein Name, der einen Zusammenhang in eine
 * Schnittstelle traegt, in die er nicht gehoert.
 */
enum ConnectionStatus: string
{
    case Active = 'active';

    /** Token ungueltig oder abgelaufen. Neu verbinden, keine Wiederholung. */
    case Expired = 'expired';

    /** Eine Berechtigung fehlt. Teilweise nutzbar, keine Wiederholung. */
    case Degraded = 'degraded';

    /** Vom Anbieter gesperrt. Alle Schreibvorgaenge anhalten. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Verbunden',
            self::Expired => 'Unterbrochen',
            self::Degraded => 'Eingeschränkt',
            self::Suspended => 'Gesperrt',
        };
    }

    public function darfSenden(): bool
    {
        return $this === self::Active || $this === self::Degraded;
    }

    public function brauchtAufmerksamkeit(): bool
    {
        return $this !== self::Active;
    }
}
