<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\AuditEvent;
use App\Enums\ImpersonationMode;
use App\Enums\Role;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Start, Freigabe und Ende einer Impersonation (Entscheidung C4).
 */
final class Impersonation
{
    public function __construct(
        private readonly AuditLogger $protokoll,
        private readonly TenantContext $mandant,
    ) {}

    /**
     * Startet eine Sitzung. **Immer maskiert** -- Vollzugriff ist ein zweiter,
     * getrennter Schritt mit Freigabe durch den Kunden.
     */
    public function start(User $superAdmin, Organization $organisation, string $begruendung): ImpersonationSession
    {
        if (! $superAdmin->isSuperAdmin()) {
            throw new RuntimeException('Impersonation setzt ein Super-Admin-Konto voraus.');
        }

        if (trim($begruendung) === '') {
            throw new InvalidArgumentException('Eine Impersonation braucht eine Begruendung.');
        }

        $this->beendeLaufende($superAdmin, 'superseded');

        $sitzung = new ImpersonationSession;
        $sitzung->organization_id = $organisation->getKey();
        $sitzung->impersonator_user_id = $superAdmin->getKey();
        $sitzung->mode = ImpersonationMode::Masked;
        $sitzung->reason = trim($begruendung);
        $sitzung->started_at = now();
        $sitzung->expires_at = now()->addMinutes(
            (int) config('mrs.impersonation.masked_ttl_minutes', 60)
        );
        $sitzung->save();

        $this->protokoll->record(
            ereignis: AuditEvent::ImpersonationStarted,
            gegenstand: $sitzung,
            kontext: ['mode' => ImpersonationMode::Masked->value],
            begruendung: $sitzung->reason,
            organizationId: $organisation->getKey(),
        );

        return $sitzung;
    }

    /**
     * Freigabe des Vollzugriffs durch eine Inhaberin des betroffenen Mandanten.
     *
     * Nicht durch den Super-Admin selbst, nicht durch irgendeine Rolle, nicht
     * unbefristet. Das ist der Kern von Entscheidung C4.
     */
    public function approve(ImpersonationSession $sitzung, User $freigebende): ImpersonationSession
    {
        if (! $freigebende->hasRole(Role::Owner)) {
            throw new RuntimeException('Nur eine Inhaberin gibt den Vollzugriff frei.');
        }

        if ($freigebende->organization_id !== $sitzung->organization_id) {
            throw new RuntimeException('Die Freigabe erteilt nur der betroffene Mandant.');
        }

        if (! $sitzung->laeuft()) {
            throw new RuntimeException('Diese Sitzung laeuft nicht mehr.');
        }

        $sitzung->mode = ImpersonationMode::Full;
        $sitzung->approved_at = now();
        $sitzung->approved_by_user_id = $freigebende->getKey();
        $sitzung->expires_at = now()->addMinutes(
            (int) config('mrs.impersonation.full_ttl_minutes', 30)
        );
        $sitzung->save();

        $this->protokoll->record(
            ereignis: AuditEvent::ImpersonationApproved,
            gegenstand: $sitzung,
            kontext: ['mode' => ImpersonationMode::Full->value],
            begruendung: $sitzung->reason,
            organizationId: $sitzung->organization_id,
        );

        return $sitzung;
    }

    public function end(ImpersonationSession $sitzung, string $grund = 'manual'): void
    {
        if ($sitzung->ended_at !== null) {
            return;
        }

        $sitzung->ended_at = now();
        $sitzung->ended_reason = $grund;
        $sitzung->save();

        $this->protokoll->record(
            ereignis: $grund === 'expired'
                ? AuditEvent::ImpersonationExpired
                : AuditEvent::ImpersonationEnded,
            gegenstand: $sitzung,
            kontext: ['ended_reason' => $grund],
            organizationId: $sitzung->organization_id,
        );
    }

    /** Die laufende Sitzung eines Super-Admins, oder null. */
    public function laufendeVon(User $superAdmin): ?ImpersonationSession
    {
        return $this->mandant->acrossTenants(
            'Laufende Impersonation eines Super-Admins suchen',
            fn (): ?ImpersonationSession => ImpersonationSession::query()
                ->where('impersonator_user_id', $superAdmin->getKey())
                ->whereNull('ended_at')
                ->first()
        );
    }

    private function beendeLaufende(User $superAdmin, string $grund): void
    {
        $laufende = $this->laufendeVon($superAdmin);

        if ($laufende instanceof ImpersonationSession) {
            $this->end($laufende, $grund);
        }
    }
}
