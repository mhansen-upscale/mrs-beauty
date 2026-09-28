<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Protokoll des Betreibers (WP-34a, `protokoll.sehen`).
 *
 * Zwei Arten von Eintraegen, die keine Praxis zu sehen bekommt oder nur zur
 * Haelfte:
 *
 * - **Eintraege ohne Organisation** -- Querzugriffe, Anmeldungen,
 *   Betreiberkonten. Bis hier gab es fuer sie keine Ansicht.
 * - **Handlungen der Betreiber an Praxen** -- sperren, gutschreiben,
 *   impersonieren. Die stehen im Protokoll der Praxis (sichtbar fuer beide
 *   Seiten, WP-34); hier stehen sie zusaetzlich nebeneinander.
 *
 * Was eine Praxis selbst tut, steht hier nicht: das ist ihr Protokoll.
 *
 * Das Lesen ist selbst ein Querzugriff und steht im Protokoll -- einer je
 * Aufruf, mit Begruendung.
 */
final class BetreiberprotokollController extends Controller
{
    /** So viele Eintraege je Seite. Wer mehr braucht, filtert. */
    private const GRENZE = 200;

    public function index(Request $request, TenantContext $mandant): Response
    {
        $request->validate(['seit' => ['nullable', 'date_format:Y-m-d']]);

        $ereignis = AuditEvent::tryFrom($request->string('ereignis')->toString());
        $betreiberKennung = $request->string('betreiber')->toString();
        $seit = $request->filled('seit')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('seit')->toString())?->startOfDay()
            : null;

        $betreiber = User::query()->whereNotNull('operator_role')->orderBy('name')->get();
        $gefiltert = Uuid::isCanonical($betreiberKennung)
            ? $betreiber->first(fn (User $konto): bool => $konto->uuid === $betreiberKennung)
            : null;

        /** @var Collection<int, AuditLog> $eintraege */
        $eintraege = $mandant->acrossTenants(
            'Backoffice zeigt dem Betreiber sein Protokoll (WP-34a)',
            fn () => AuditLog::query()
                ->where(fn (Builder $abfrage) => $abfrage
                    ->whereNull('organization_id')
                    ->orWhereIn('actor_user_id', $betreiber->modelKeys())
                    // Ein geloeschtes Konto steht nicht mehr in users. Seine
                    // Handlungen an Praxen findet das Protokoll ueber den
                    // Eintrag, der das Loeschen festhaelt (28.09.2026).
                    ->orWhereIn('actor_user_id', fn (QueryBuilder $geloeschte) => $geloeschte
                        ->select('subject_id')
                        ->from('audit_logs')
                        ->whereNull('organization_id')
                        ->where('event', AuditEvent::OperatorDeleted->value)
                        ->where('subject_type', (new User)->getMorphClass())))
                ->when($ereignis instanceof AuditEvent, fn (Builder $abfrage) => $abfrage->where('event', $ereignis?->value))
                ->when($gefiltert instanceof User, fn (Builder $abfrage) => $abfrage->where('actor_user_id', $gefiltert?->getKey()))
                ->when($seit instanceof CarbonImmutable, fn (Builder $abfrage) => $abfrage->where('occurred_at', '>=', $seit))
                ->orderByDesc('occurred_at')
                ->limit(self::GRENZE)
                ->get(),
        );

        // Die Namen der Praxen in einer Abfrage, nicht je Zeile.
        $praxen = Organization::query()
            ->whereIn('id', $eintraege->map(fn (AuditLog $eintrag): mixed => $eintrag->getAttributes()['organization_id'] ?? null)->filter()->unique()->values()->all())
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Organization $praxis): array => [(string) $praxis->getKey() => $praxis->name]);

        return Inertia::render('backoffice/Protokoll', [
            'eintraege' => $eintraege->map(fn (AuditLog $eintrag): array => [
                'uuid' => $eintrag->uuid,
                'ereignis' => $eintrag->event->value,
                'label' => $eintrag->event->label(),
                'handelnde' => $eintrag->actor_label,
                'praxis' => $praxen->get((string) ($eintrag->getAttributes()['organization_id'] ?? '')),
                'begruendung' => $eintrag->reason,
                'kontext' => $eintrag->context ?? [],
                'impersoniert' => $eintrag->getAttribute('impersonation_session_id') !== null,
                'zeitpunkt' => $eintrag->occurred_at->toIso8601String(),
            ])->values(),
            'filter' => [
                'ereignis' => $ereignis?->value,
                'betreiber' => $gefiltert?->uuid,
                'seit' => $seit?->format('Y-m-d'),
            ],
            'ereignisse' => array_map(fn (AuditEvent $fall): array => ['wert' => $fall->value, 'label' => $fall->label()], AuditEvent::cases()),
            'betreiber' => $betreiber->map(fn (User $konto): array => ['uuid' => $konto->uuid, 'name' => $konto->name])->values(),
            'grenze' => self::GRENZE,
        ]);
    }
}
