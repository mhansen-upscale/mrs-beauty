<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was das Abo einer Praxis ihr gerade erlaubt (WP-34c).
 *
 * **Die eine Stelle, die jede Abo-Sperre kennt.** Unbezahlt, pausiert,
 * Testphase abgelaufen, gekuendigt: vier Wege zur selben Wirkung. Wer an
 * vier Stellen einzeln fragt, vergisst an einer davon einen -- und dann
 * laeuft der Agent einer Praxis, die niemand bezahlt.
 *
 * Gerechnet in Subscription::zugang() und App\Abrechnung\Abozugang. Eine
 * **Betreiber-Sperre** (`suspended_at`) ist etwas anderes und steht nicht hier
 * (WP-34a): sie laesst nicht einmal die Abo-Seite offen.
 */
enum SubscriptionAccess: string
{
    /** Bezahlt -- auch mit offener Zahlung, solange Stripe mahnt (WP-06). */
    case Open = 'open';

    case Trial = 'trial';

    case TrialExpired = 'trial_expired';

    /** Der Einzug ruht (B17): keine Rechnung, also auch kein Zugang. */
    case Paused = 'paused';

    /** Nach der letzten Mahnung (WP-06). */
    case Unpaid = 'unpaid';

    case Canceled = 'canceled';

    public function sperrtZugang(): bool
    {
        return ! $this->darfNutzen();
    }

    public function darfNutzen(): bool
    {
        return $this === self::Open || $this === self::Trial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aktiv',
            self::Trial => 'Testphase',
            self::TrialExpired => 'Testphase abgelaufen',
            self::Paused => 'Pausiert',
            self::Unpaid => 'Zahlung ausgeblieben',
            self::Canceled => 'Gekündigt',
        };
    }

    /**
     * Was die Praxis liest, wenn sie nicht hineinkommt.
     *
     * **Kein Vorwurf** -- sie hat nichts falsch gemacht, sie hat etwas offen.
     * Und immer der Satz, dass die Daten bleiben: eine Sperre, die klingt, als
     * waere alles weg, fuehrt zu Anrufen, nicht zu Zahlungen.
     */
    public function hinweis(): string
    {
        return match ($this) {
            self::Open, self::Trial => '',
            self::TrialExpired => 'Die Testphase ist abgelaufen. Mit einem Abo steht alles wieder offen — Ihre Daten bleiben unverändert.',
            self::Paused => 'Das Abo ruht. Solange es pausiert ist, bleibt der Zugang gesperrt — Ihre Daten bleiben unverändert.',
            self::Unpaid => 'Der Zugang ist gesperrt, weil die Zahlung ausgeblieben ist. Sobald sie eingeht, steht alles wieder offen — Ihre Daten bleiben unverändert.',
            self::Canceled => 'Das Abo ist beendet. Mit einem neuen Abo steht alles wieder offen — Ihre Daten bleiben bis zum Ende der Aufbewahrung erhalten.',
        };
    }
}
