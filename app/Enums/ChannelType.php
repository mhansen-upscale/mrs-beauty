<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ueber welchen Weg eine Person erreichbar ist.
 *
 * Als VARCHAR plus PHP-Enum (Entscheidung A11). WP-20 ergaenzt Verhalten,
 * keinen Fall -- die Kanaele stehen seit `docs/produkt.md` fest.
 *
 * **Bedient werden davon zwei**: WhatsApp und E-Mail (Entscheidung P11).
 * `messenger` und `instagram` bleiben als Faelle stehen, weil eine Praxis die
 * Kennung einer Person dort erfassen darf und `channel_identities` scoped IDs
 * abbildet -- einen Leser oder einen Versand haben sie nicht. `phone` hatte
 * nie einen (Entscheidung P3).
 *
 * **Nicht zu verwechseln mit NotificationChannel.** Der sagt, worueber das
 * System etwas verschickt; dieser hier sagt, unter welcher Kennung ein Mensch
 * bei einem Anbieter gefuehrt wird.
 */
enum ChannelType: string
{
    case Email = 'email';

    case Phone = 'phone';

    case WhatsApp = 'whatsapp';

    case Instagram = 'instagram';

    case Messenger = 'messenger';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-Mail',
            self::Phone => 'Telefon',
            self::WhatsApp => 'WhatsApp',
            self::Instagram => 'Instagram',
            self::Messenger => 'Messenger',
        };
    }

    /**
     * Gilt fuer diesen Kanal ein Service-Fenster?
     *
     * **Ein Begriff von Meta, kein allgemeiner.** 24 Stunden ab der letzten
     * eingehenden Nachricht, danach nur noch ein genehmigtes Template
     * (docs/integrationen/meta.md). Eine E-Mail kennt das nicht: sie darf
     * jederzeit beantwortet werden, und ein Fenster in der Spalte waere eine
     * Frist, die niemand gesetzt hat -- die Inbox zeigte sie an, und die
     * Kostenanzeige waere falsch.
     */
    public function hatServicefenster(): bool
    {
        return $this === self::WhatsApp || $this === self::Instagram || $this === self::Messenger;
    }

    /**
     * Ist die Kennung dieses Kanals eine Telefonnummer?
     *
     * Dann wird sie nach E.164 normalisiert, bevor daraus ein Index wird --
     * sonst waeren dieselbe Nummer in zwei Schreibweisen zwei Identitaeten.
     */
    public function istRufnummer(): bool
    {
        return $this === self::Phone || $this === self::WhatsApp;
    }
}
