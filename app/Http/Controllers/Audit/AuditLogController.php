<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Protokollansicht der eigenen Organisation.
 *
 * AuditLog ist ein TenantModel -- der Global Scope schraenkt hier bereits ein.
 * Mandantenuebergreifende Eintraege (organization_id IS NULL) sieht damit
 * niemand ausser dem Backoffice aus WP-34.
 */
final class AuditLogController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Ability::ViewAuditLog->value);

        $eintraege = AuditLog::query()
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return Inertia::render('organisation/Protokoll', [
            'entries' => $eintraege->map(fn (AuditLog $eintrag): array => [
                'uuid' => $eintrag->uuid,
                'event' => $eintrag->event->value,
                'label' => $eintrag->event->label(),
                'actor' => $eintrag->actor_label,
                'subject' => $eintrag->subject_type === null
                    ? null
                    : class_basename($eintrag->subject_type),
                'fields' => $eintrag->changed_fields ?? [],
                'context' => $eintrag->context ?? [],
                'reason' => $eintrag->reason,
                'impersonated' => $eintrag->getAttribute('impersonation_session_id') !== null,
                'occurred_at' => $eintrag->occurred_at->toIso8601String(),
            ])->values(),
        ]);
    }
}
