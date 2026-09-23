<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| Die Einfuehrung laeuft einmal von selbst
|--------------------------------------------------------------------------
|
| Wer sich zum ersten Mal anmeldet, steht vor einer Seitenleiste mit bis zu
| neunzehn Punkten und keiner Erklaerung. Die Fuehrung startet deshalb einmal
| von selbst -- und danach nie wieder, bis jemand sie ausdruecklich erneut
| aufruft.
|
| **Der Zustand haengt an der Person, nicht an der Praxis.** Ein Merkmal in
| organizations.settings waere mandantenweit: die zweite Mitarbeiterin saehe
| die Einfuehrung dann nie.
|
| **Und er kommt serverseitig mit**, wie sidebar_open. Ein Overlay, das erst
| nach onMounted entscheidet, ob es erscheint, blitzt bei jedem Seitenaufruf
| kurz auf.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'UTC'));
});

it('ist bei einer frisch angelegten Person faellig', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    expect($inhaberin->fresh()?->einfuehrung_gesehen_at)->toBeNull();

    actingAs($inhaberin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('einfuehrung_faellig', true));
});

it('ist nach dem Abschliessen nicht mehr faellig', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->post(route('einfuehrung.gesehen'))
        ->assertRedirect();

    expect($inhaberin->fresh()?->einfuehrung_gesehen_at)->not->toBeNull();

    actingAs($inhaberin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('einfuehrung_faellig', false));
});

it('haelt den Zeitpunkt der ersten Sichtung fest', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)->post(route('einfuehrung.gesehen'));

    $zuerst = $inhaberin->fresh()?->einfuehrung_gesehen_at;

    travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));

    // Wer die Fuehrung erneut ansieht, hat sie nicht zum ersten Mal gesehen.
    actingAs($inhaberin)->post(route('einfuehrung.gesehen'));

    expect($inhaberin->fresh()?->einfuehrung_gesehen_at?->toIso8601String())
        ->toBe($zuerst?->toIso8601String());
});

it('laesst niemanden ohne Anmeldung abschliessen', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    post(route('einfuehrung.gesehen'))->assertRedirect(route('login'));
});

it('gilt fuer jede Rolle, nicht nur fuer die Inhaberin', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    foreach ([Role::Admin, Role::Reception, Role::Practitioner, Role::Marketing] as $rolle) {
        $person = User::factory()->fuer($organisation, $rolle)->create();

        actingAs($person)
            ->get(route('dashboard'))
            ->assertInertia(fn ($seite) => $seite->where('einfuehrung_faellig', true));
    }
});

it('haelt die oeffentliche Buchungsseite ohne Anmeldung erreichbar', function (): void {
    // share() laeuft auch dort -- ohne den instanceof-Waechter zerlegt das
    // Merkmal die Seite, die gar keinen Benutzer hat.
    $praxis = Organization::factory()->create(['name' => 'Demo-Praxis', 'slug' => 'demo-praxis']);

    get(route('buchung.zeigen', ['praxis' => $praxis->slug]))->assertSuccessful();
});
