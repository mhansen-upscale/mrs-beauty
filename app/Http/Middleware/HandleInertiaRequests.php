<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\ImpersonationContext;
use App\Enums\Ability;
use App\Enums\CalendarConnectionStatus;
use App\Enums\OperatorAbility;
use App\Models\CalendarConnection;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    /**
     * @return array<string, mixed>|null
     */
    private function impersonation(): ?array
    {
        $kontext = app(ImpersonationContext::class);
        $sitzung = $kontext->current();

        if (! $sitzung instanceof ImpersonationSession) {
            return null;
        }

        return [
            'uuid' => $sitzung->uuid,
            'mode' => $sitzung->mode->value,
            'mode_label' => $sitzung->mode->label(),
            'masked' => $kontext->masks(),
            'reason' => $sitzung->reason,
            'expires_at' => $sitzung->expires_at->toIso8601String(),
        ];
    }

    /**
     * Gibt es eine unterbrochene Kalenderverbindung?
     *
     * Eine indizierte Existenzabfrage je Anfrage -- und nur fuer angemeldete
     * Personen mit Mandantenbezug. Die oeffentliche Buchungsseite stellt sie
     * nicht.
     */
    private function kalenderstoerung(mixed $benutzer, ?Organization $organisation): bool
    {
        if (! $benutzer instanceof User || ! $organisation instanceof Organization) {
            return false;
        }

        return CalendarConnection::query()
            ->where('status', CalendarConnectionStatus::Expired->value)
            ->exists();
    }

    /**
     * Der Hinweis erscheint nicht waehrend der Einfuehrung -- ein Overlay
     * genuegt -- und nicht in einer Impersonation: der Support sieht die
     * Praxis, nicht sein eigenes Konto.
     *
     * @return array{aktiv: bool, verfahren: string|null, hinweis: bool}
     */
    private function zweiFaktor(User $benutzer): array
    {
        return [
            'aktiv' => $benutzer->hatZweiFaktor(),
            'verfahren' => $benutzer->zweiFaktorVerfahren()?->value,
            'hinweis' => $benutzer->zweiFaktorHinweisFaellig()
                && ! $benutzer->einfuehrungStehtAus()
                && ! app(ImpersonationContext::class)->isActive(),
        ];
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $benutzer = $request->user();
        $organisation = app(TenantContext::class)->current();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $benutzer,
                'role' => $benutzer instanceof User ? $benutzer->role?->value : null,

                // Die Rolle des Betreibers und was sie darf (WP-34a). Sie
                // haengt nicht an einer Praxisrolle -- der Betreiber gehoert
                // zu keiner Praxis. Wie `abilities` eine Bequemlichkeit fuer
                // die Oberflaeche; die Tuer ist EnsureBetreiber.
                'betreiber' => $benutzer instanceof User && $benutzer->istBetreiber() ? [
                    'rolle' => $benutzer->betreiberRolle()?->value,
                    'rolleLabel' => $benutzer->betreiberRolle()?->label(),
                    'faehigkeiten' => array_values(array_map(
                        fn (OperatorAbility $faehigkeit): string => $faehigkeit->value,
                        array_filter(OperatorAbility::cases(), fn (OperatorAbility $faehigkeit): bool => $benutzer->betreiberDarf($faehigkeit)),
                    )),
                ] : null,

                // Der zweite Faktor (WP-35). **Nur Verfahren und Hinweis**,
                // nie Geheimnis oder Codes -- die stehen in User::$hidden.
                'zweiFaktor' => $benutzer instanceof User ? $this->zweiFaktor($benutzer) : null,
            ],

            // Die Oberflaeche blendet danach aus, was jemand nicht darf. Das
            // ist eine Bequemlichkeit, keine Zugangskontrolle -- die steht in
            // den Gates und in den Controllern.
            'abilities' => $benutzer instanceof User
                ? collect(Ability::cases())
                    ->filter(fn (Ability $ability): bool => $benutzer->hasAbility($ability))
                    ->map(fn (Ability $ability): string => $ability->value)
                    ->values()
                    ->all()
                : [],

            // Die Seitenleiste rendert sonst immer erst aufgeklappt und
            // klappt nach onMounted zusammen -- ein sichtbarer Sprung des
            // ganzen Inhalts bei jedem Aufruf. SidebarProvider schreibt den
            // Zustand ohnehin in dieses Cookie.
            'sidebar_open' => $request->cookie('sidebar:state') !== 'false',

            // **Die Einfuehrung startet einmal von selbst.** Serverseitig
            // mitgegeben aus demselben Grund wie der Zustand der
            // Seitenleiste: ein Overlay, das erst nach onMounted
            // entscheidet, ob es erscheint, blitzt bei jedem Aufruf auf.
            //
            // Der instanceof-Waechter ist Pflicht -- share() laeuft auch auf
            // der oeffentlichen Buchungsseite, die keinen Benutzer hat.
            'einfuehrung_faellig' => $benutzer instanceof User && $benutzer->einfuehrungStehtAus(),

            'organization' => $organisation === null ? null : [
                'uuid' => $organisation->uuid,
                'name' => $organisation->name,
            ],

            // Solange eine Impersonation laeuft, ist sie in **jeder** Antwort
            // erkennbar (WP-05, Abnahmekriterium 18).
            'impersonation' => $this->impersonation(),

            // R4 aus docs/integrationen/kalender.md: ein Ausfall erzeugt einen
            // Hinweis **im Produkt**, nicht nur im Log. Eine unterbrochene
            // Verbindung heisst, dass Termine ueber belegten Zeiten gebucht
            // werden -- das gehoert nicht auf eine Unterseite.
            'calendar_alert' => $this->kalenderstoerung($benutzer, $organisation),

            // **Drei Arten, weil sie drei verschiedene Dinge sagen.**
            // `hinweise` kam mit WP-07 dazu: etwas hat geklappt, aber nicht
            // ganz so, wie es eingegeben wurde -- eine Markenfarbe, die
            // abgedunkelt werden musste, ist weder Erfolg noch Fehler.
            'flash' => [
                'erfolg' => $request->hasSession() ? $request->session()->get('erfolg') : null,
                'fehler' => $request->hasSession() ? $request->session()->get('fehler') : null,
                'hinweise' => $request->hasSession() ? $request->session()->get('hinweise', []) : [],
            ],
        ];
    }
}
