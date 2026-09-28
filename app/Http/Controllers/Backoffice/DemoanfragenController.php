<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\DemoRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Demo-Anfragen der Startseite (WP-38, `demoanfragen.verwalten`).
 *
 * **Kein Querzugriff:** Die Anfragen gehoeren keiner Praxis, es gibt keine
 * Mandantengrenze zu ueberschreiten -- anders als im Betreiberprotokoll.
 *
 * **Keine Suche.** Die Angaben liegen verschluesselt; eine Suche im Browser
 * ueber die geladene Seite taeuschte eine Vollstaendigkeit vor, die sie nicht
 * hat. Gefiltert wird nach Status, auf dem Server.
 *
 * Protokolliert wird, was ein Betreiber tut -- mit dem Status, nie mit einer
 * Angabe der Anfrage (C5).
 */
final class DemoanfragenController extends Controller
{
    /** So viele Anfragen je Seite. Wer mehr braucht, filtert. */
    private const GRENZE = 200;

    public function __construct(private readonly AuditLogger $protokoll) {}

    public function index(Request $request): Response
    {
        $status = DemoRequestStatus::tryFrom($request->string('status')->toString());

        $anfragen = DemoRequest::query()
            ->when($status instanceof DemoRequestStatus, fn ($abfrage) => $abfrage->where('status', $status?->value))
            ->orderByDesc('created_at')
            ->limit(self::GRENZE)
            ->get();

        return Inertia::render('backoffice/Demoanfragen', [
            'anfragen' => $anfragen->map(fn (DemoRequest $anfrage): array => [
                'uuid' => (string) $anfrage->uuid,
                'name' => $anfrage->name,
                'praxis' => $anfrage->practice_name,
                'email' => $anfrage->email,
                'telefon' => $anfrage->phone,
                'ort' => $anfrage->city,
                'status' => $anfrage->status->value,
                'statusLabel' => $anfrage->status->label(),
                'eingegangen' => $anfrage->created_at?->toIso8601String(),
                'statusGeaendert' => $anfrage->status_changed_at?->toIso8601String(),
            ])->values(),
            'filter' => ['status' => $status?->value],
            'status' => array_map(
                fn (DemoRequestStatus $fall): array => ['wert' => $fall->value, 'label' => $fall->label()],
                DemoRequestStatus::cases(),
            ),
            'grenze' => self::GRENZE,
        ]);
    }

    /**
     * **Ohne Passwort** (zu bestaetigen): ein Statuswechsel ist umkehrbar und
     * betrifft keine Praxis. Loeschen ist es nicht.
     */
    public function status(Request $request, DemoRequest $demoanfrage): RedirectResponse
    {
        $daten = $request->validate([
            'status' => ['required', Rule::enum(DemoRequestStatus::class)],
        ]);

        $neu = DemoRequestStatus::from((string) $daten['status']);

        if ($neu === $demoanfrage->status) {
            return back();
        }

        $demoanfrage->status = $neu;
        $demoanfrage->status_changed_at = CarbonImmutable::now();
        $demoanfrage->save();

        $this->protokoll->record(
            AuditEvent::DemoRequestStatusChanged,
            gegenstand: $demoanfrage,
            kontext: ['status' => $neu->value],
            ohneOrganisation: true,
        );

        return back()->with('erfolg', "Status: {$neu->label()}.");
    }

    public function loeschen(Request $request, DemoRequest $demoanfrage): RedirectResponse
    {
        $request->validate(BackofficeController::PASSWORT);

        // Erst protokollieren: danach gibt es den Schluessel nicht mehr, auf
        // den der Eintrag zeigt.
        $this->protokoll->record(AuditEvent::DemoRequestDeleted, gegenstand: $demoanfrage, ohneOrganisation: true);

        $demoanfrage->delete();

        return back()->with('erfolg', 'Anfrage gelöscht.');
    }
}
