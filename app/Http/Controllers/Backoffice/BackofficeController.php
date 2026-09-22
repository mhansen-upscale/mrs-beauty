<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Abrechnung\Kontingente;
use App\Audit\AuditLogger;
use App\Backoffice\Mandantenuebersicht;
use App\Betrieb\Betriebslage;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Backoffice des Betreibers.
 *
 * **Zustaende und Zahlen, keine Inhalte.** Wer in eine Praxis hineinsehen
 * muss, geht ueber die Impersonation aus WP-05 -- mit Begruendung, mit
 * Freigabe durch die Praxis, im Protokoll.
 *
 * Jede Handlung hier wirkt ueber Mandantengrenzen hinweg und wird deshalb
 * protokolliert: sperren, entsperren, gutschreiben. Ein Backoffice, das
 * stillschweigend eingreift, waere genau die Luecke, die Regel 1 schliesst.
 */
final class BackofficeController extends Controller
{
    public function __construct(
        private readonly Mandantenuebersicht $uebersicht,
        private readonly Betriebslage $lage,
        private readonly AuditLogger $protokoll,
    ) {}

    public function index(Request $request): Response
    {
        $suche = trim((string) $request->query('suche', ''));

        return Inertia::render('backoffice/Index', [
            'suche' => $suche,
            'mandanten' => $this->uebersicht->liste($suche),
            'installation' => $this->lage->fuerInstallation(),
        ]);
    }

    public function show(string $organisation): Response
    {
        $praxis = $this->praxis($organisation);

        return Inertia::render('backoffice/Mandant', [
            'mandant' => $this->uebersicht->blatt($praxis),
        ]);
    }

    /**
     * Sperrt eine Praxis.
     *
     * Der Zugang faellt sofort weg -- geprueft wird bei jeder Anfrage, nicht
     * beim Anmelden.
     */
    public function sperren(Request $request, string $organisation): RedirectResponse
    {
        $daten = $request->validate([
            'grund' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $praxis = $this->praxis($organisation);

        $praxis->suspended_at = CarbonImmutable::now();
        $praxis->save();

        $this->vermerke(AuditEvent::TenantSuspended, $praxis, $daten['grund']);

        return back();
    }

    public function entsperren(Request $request, string $organisation): RedirectResponse
    {
        $daten = $request->validate([
            'grund' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $praxis = $this->praxis($organisation);

        $praxis->suspended_at = null;
        $praxis->save();

        $this->vermerke(AuditEvent::TenantUnsuspended, $praxis, $daten['grund']);

        return back();
    }

    /**
     * Schreibt Kontingent gut -- als Kulanz, nicht als Verkauf.
     *
     * Eine Praxis, der wir eine Woche lang die Warteliste kaputtgemacht
     * haben, bekommt ihr Kontingent zurueck, ohne dafuer zu zahlen. Der
     * Vorgang steht im Protokoll, mit Begruendung und Namen.
     */
    public function gutschreiben(Request $request, string $organisation, TenantContext $mandant, Kontingente $kontingente): RedirectResponse
    {
        $daten = $request->validate([
            'art' => ['required', 'in:nachrichten,agentenlaeufe'],
            'menge' => ['required', 'integer', 'between:1,5000'],
            'grund' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $praxis = $this->praxis($organisation);

        $mandant->runAs($praxis, function () use ($kontingente, $daten): void {
            $kontingente->stockeAuf((string) $daten['art'], (int) $daten['menge']);
        });

        $this->vermerke(
            AuditEvent::TenantCredited,
            $praxis,
            $daten['grund'].' ('.$daten['menge'].' '.$daten['art'].')',
        );

        return back();
    }

    private function praxis(string $uuid): Organization
    {
        // Der Betreiber gehoert zu keiner Organisation -- die Suche laeuft
        // deshalb ausdruecklich ueber die Grenze, mit Begruendung.
        return app(TenantContext::class)->acrossTenants(
            'Backoffice oeffnet das Blatt eines Mandanten (WP-34)',
            fn (): Organization => Organization::query()->whereUuid($uuid)->firstOrFail(),
        );
    }

    /**
     * Jede Handlung des Betreibers steht im Protokoll (Regel 1).
     *
     * **Beim Mandanten**, nicht beim Betreiber: die Praxis soll nachlesen
     * koennen, was mit ihr geschehen ist -- ein Protokoll, das nur der
     * Betreiber sieht, ist keine Kontrolle, sondern eine Notiz.
     */
    private function vermerke(AuditEvent $ereignis, Organization $praxis, string $grund): void
    {
        app(TenantContext::class)->runAs($praxis, function () use ($ereignis, $praxis, $grund): void {
            $this->protokoll->record(
                ereignis: $ereignis,
                gegenstand: $praxis,
                begruendung: $grund,
            );
        });
    }
}
