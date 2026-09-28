<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Abrechnung\Aboeingriffe;
use App\Abrechnung\Kontingente;
use App\Audit\AuditLogger;
use App\Backoffice\Mandantenuebersicht;
use App\Enums\AuditEvent;
use App\Enums\SubscriptionChangeAction;
use App\Enums\SubscriptionChangeStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    /**
     * **Das eigene Passwort vor jeder wirksamen Handlung** (WP-34a, C14) --
     * der Ausgleich dafuer, dass der zweite Faktor freiwillig ist (C16). Als
     * Feld im Dialog, nicht als `password.confirm`: dessen Mittelschicht
     * merkt sich bei POST die POST-Adresse als Ziel und fuehrt nach der
     * Bestaetigung auf einen 405, und `auth.password_timeout` betraegt drei
     * Stunden.
     */
    public const PASSWORT = ['current_password' => ['required', 'current_password']];

    public function __construct(
        private readonly Mandantenuebersicht $uebersicht,
        private readonly AuditLogger $protokoll,
    ) {}

    public function index(Request $request): Response
    {
        $suche = trim((string) $request->query('suche', ''));

        return Inertia::render('backoffice/Index', [
            'suche' => $suche,
            // Die Kennzahlen der Installation stehen auf dem Dashboard des
            // Betreibers; hier nur die Liste.
            'mandanten' => $this->uebersicht->liste($suche),

            // Fuer den Filter "Testphase endet bald" (WP-34c).
            'warnungTage' => (int) config('mrs.backoffice.testphase_warnung_tage'),
        ]);
    }

    public function show(string $organisation): Response
    {
        $praxis = $this->praxis($organisation);

        return Inertia::render('backoffice/Mandant', [
            'mandant' => $this->uebersicht->blatt($praxis),
            'maxTestphaseTage' => (int) config('mrs.billing.trial_verlaengerung_max_tage'),
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
            ...self::PASSWORT,
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
            ...self::PASSWORT,
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
            ...self::PASSWORT,
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

    /**
     * Ein Eingriff in das Abo (WP-34c, `abo.eingreifen`): pausieren,
     * fortsetzen, kuendigen, Kuendigung zuruecknehmen, Gratismonat.
     *
     * **Beauftragt, nicht erledigt.** Stripe bekommt den Auftrag ueber die
     * Warteschlange, den Zustand meldet der Webhook. Im Testbetrieb ohne
     * Stripe wirkt er sofort -- und die Meldung sagt es.
     */
    public function abo(Request $request, string $organisation, Aboeingriffe $eingriffe): RedirectResponse
    {
        $daten = $request->validate([
            'aktion' => ['required', Rule::in(array_map(
                fn (SubscriptionChangeAction $aktion): string => $aktion->value,
                array_filter(SubscriptionChangeAction::cases(), fn (SubscriptionChangeAction $aktion): bool => $aktion->beiStripe()),
            ))],
            // Nur fuer die Pause: wann Stripe den Einzug von selbst fortsetzt.
            'bis' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'grund' => ['required', 'string', 'min:5', 'max:200'],
            ...self::PASSWORT,
        ]);

        $betreiber = $request->user();
        abort_unless($betreiber instanceof User, 403);

        $aktion = SubscriptionChangeAction::from((string) $daten['aktion']);
        $bis = $aktion === SubscriptionChangeAction::Pause && is_string($daten['bis'] ?? null) ? ['bis' => $daten['bis']] : [];

        $eingriff = $eingriffe->beauftrage($this->praxis($organisation), $aktion, (string) $daten['grund'], $betreiber, $bis);

        return back()->with('erfolg', match (true) {
            $eingriff->ohneStripe() => "{$aktion->label()}: ohne Stripe (Testbetrieb) sofort wirksam.",
            $eingriff->status === SubscriptionChangeStatus::Failed => "{$aktion->label()}: Stripe hat abgelehnt. Der Grund steht unten bei den Eingriffen.",
            $eingriff->status === SubscriptionChangeStatus::Done => "{$aktion->label()}: Stripe hat den Auftrag angenommen. Den neuen Zustand meldet Stripe gleich.",
            default => "{$aktion->label()}: beauftragt.",
        });
    }

    /**
     * Verlaengert die Testphase (WP-34c, `testphase.verlaengern`) -- lokal und
     * sofort, hoechstens um `trial_verlaengerung_max_tage`.
     */
    public function testphase(Request $request, string $organisation, Aboeingriffe $eingriffe): RedirectResponse
    {
        $daten = $request->validate([
            'tage' => ['required', 'integer', 'min:1', 'max:'.(int) config('mrs.billing.trial_verlaengerung_max_tage')],
            'grund' => ['required', 'string', 'min:5', 'max:200'],
            ...self::PASSWORT,
        ]);

        $betreiber = $request->user();
        abort_unless($betreiber instanceof User, 403);

        $eingriffe->verlaengereTestphase($this->praxis($organisation), (int) $daten['tage'], (string) $daten['grund'], $betreiber);

        return back()->with('erfolg', "Testphase um {$daten['tage']} Tage verlängert.");
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
