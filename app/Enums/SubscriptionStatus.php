<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie es um das Abo einer Praxis steht.
 *
 * Die Zustaende sind die von Stripe -- **uebernommen, nicht nachgebildet**
 * (Entscheidung B9). Ein eigener Zustandsautomat neben dem des Anbieters
 * weicht ab, sobald jemand im Stripe-Portal etwas tut.
 */
enum SubscriptionStatus: string
{
    /** Noch kein Abo abgeschlossen -- und trotzdem arbeitsfaehig. */
    case Trialing = 'trialing';

    case Active = 'active';

    /** Zahlung offen. Stripe mahnt; das Produkt bleibt zunaechst offen. */
    case PastDue = 'past_due';

    /**
     * Stripe hat aufgegeben.
     *
     * Der Zustand nach der **letzten** Mahnung: alle Einzugsversuche sind
     * gescheitert. Ab hier ist der Zugang gesperrt -- die
     * Betreiberentscheidung, die WP-06 offengelassen hatte.
     */
    case Unpaid = 'unpaid';

    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Testphase',
            self::Active => 'Aktiv',
            self::PastDue => 'Zahlung offen',
            self::Unpaid => 'Gesperrt — Zahlung ausgeblieben',
            self::Canceled => 'Gekündigt',
        };
    }

    /**
     * Darf die Praxis kostenpflichtige Dinge tun?
     *
     * **Auch bei offener Zahlung ja.** Stripe mahnt mehrfach, bevor es
     * aufgibt; wer beim ersten fehlgeschlagenen Einzug die Kommunikation
     * einer Praxis abschaltet, verliert einen Kunden wegen einer abgelaufenen
     * Karte.
     *
     * **Nach der letzten Mahnung nein.** Dann ist es keine abgelaufene Karte
     * mehr, sondern eine Entscheidung.
     */
    public function darfNutzen(): bool
    {
        return $this !== self::Canceled && $this !== self::Unpaid;
    }

    /**
     * Ist der Zugang gesperrt?
     *
     * Gekuendigt und unbezahlt unterscheiden sich im Ton, nicht in der
     * Wirkung: beide kommen nicht mehr hinein, aber nur eines davon laesst
     * sich mit einer Zahlung loesen.
     */
    public function sperrtZugang(): bool
    {
        return $this === self::Unpaid || $this === self::Canceled;
    }

    public static function ausStripe(string $wert): self
    {
        return match ($wert) {
            'active' => self::Active,
            'trialing' => self::Trialing,
            'past_due', 'incomplete' => self::PastDue,

            // **Stripes Endstation.** `unpaid` setzt Stripe, wenn alle
            // Einzugsversuche gescheitert sind -- nicht beim ersten.
            'unpaid' => self::Unpaid,
            default => self::Canceled,
        };
    }
}
