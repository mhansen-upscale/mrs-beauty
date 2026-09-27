<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ein Eingriff des Betreibers in das Abo einer Praxis (WP-34c, B17).
 *
 * Alle ausser der Testphase laufen als Auftrag bei Stripe; den Zustand
 * meldet der Webhook. Die Testphase lebt nur bei uns.
 */
enum SubscriptionChangeAction: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case CancelPeriodEnd = 'cancel_period_end';
    case RevokeCancel = 'revoke_cancel';
    case CancelNow = 'cancel_now';
    case FreeMonth = 'free_month';
    case ExtendTrial = 'extend_trial';

    public function label(): string
    {
        return match ($this) {
            self::Pause => 'Pausieren',
            self::Resume => 'Fortsetzen',
            self::CancelPeriodEnd => 'Zum Periodenende kündigen',
            self::RevokeCancel => 'Kündigung zurücknehmen',
            self::CancelNow => 'Sofort kündigen',
            self::FreeMonth => 'Gratismonat',
            self::ExtendTrial => 'Testphase verlängern',
        };
    }

    /** Braucht der Eingriff Stripe -- oder lebt er nur bei uns? */
    public function beiStripe(): bool
    {
        return $this !== self::ExtendTrial;
    }
}
