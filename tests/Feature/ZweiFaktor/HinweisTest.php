<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 41 bis 43 -- der Hinweis statt einer Pflicht
|--------------------------------------------------------------------------
|
| Freiwillig heisst nicht beilaeufig (C16). Wer keinen zweiten Faktor hat,
| sieht einen Hinweis, der die Arbeit nicht versperrt und sich fuer eine
| Weile ausblenden laesst -- auf jedem Geraet, nicht nur auf diesem.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('zeigt Praxis und Betreiber ohne zweiten Faktor den Hinweis, mit nicht', function (): void {
    $ohne = Zugang::inhaberin();
    $mit = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator(), 'zweite@praxis.test');
    $betreiber = User::factory()->superAdmin()->create(['einfuehrung_gesehen_at' => CarbonImmutable::now()]);

    actingAs($ohne)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', true)->where('auth.zweiFaktor.aktiv', false));
    actingAs($mit)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', false)->where('auth.zweiFaktor.aktiv', true));
    actingAs($betreiber)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', true));
});

it('blendet den Hinweis fuer eine Weile aus, auf jedem Geraet', function (): void {
    $person = Zugang::inhaberin();

    // Drei Monate spaeter ist die Testphase vorbei (B18) -- die Praxis zahlt.
    alsMandant(Organization::query()->findOrFail($person->organization_id));
    bezahltesAbo();
    ohneMandant();

    actingAs($person)->post(route('zwei-faktor.hinweis'))->assertRedirect();

    // Eine andere Sitzung, derselbe Stand: er haengt an der Person.
    session()->flush();
    actingAs($person)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', false));

    travel((int) config('mrs.zwei_faktor.hinweis_pause_tage') + 1)->days();

    actingAs($person->fresh() ?? $person)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', true));
});

it('zeigt den Hinweis nicht waehrend der Einfuehrung, nicht in der Impersonation und nicht ohne Anmeldung', function (): void {
    $neu = Zugang::inhaberin(fn ($f) => $f->state(['einfuehrung_gesehen_at' => null]));

    actingAs($neu)->get(route('dashboard'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', false));

    $praxis = Organization::query()->findOrFail($neu->organization_id);
    alsMandant($praxis);
    $betreiber = User::factory()->superAdmin()->create(['einfuehrung_gesehen_at' => CarbonImmutable::now()]);
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor.hinweis', false));

    // Wer nicht angemeldet ist -- wie auf der Buchungsseite --, bekommt nichts.
    auth()->logout();
    get(route('login'))->assertInertia(fn ($seite) => $seite->where('auth.zweiFaktor', null));
});
