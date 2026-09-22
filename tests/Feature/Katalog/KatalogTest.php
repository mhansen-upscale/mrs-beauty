<?php

declare(strict_types=1);

use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\Treatment;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| WP-09, Abnahmekriterien 1 bis 19
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

// --- Katalog ---------------------------------------------------------------

it('speichert keine Behandlung ohne Umsatzschaetzung', function (): void {
    // Entscheidung D14: ohne diesen Wert kein ROAS. Die Datenbank haelt das
    // fest, nicht nur ein Formular.
    Treatment::query()->create([
        'name' => 'Ohne Wert',
        'slug' => 'ohne-wert',
    ]);
})->throws(QueryException::class);

it('speichert keinen negativen Umsatzwert', function (): void {
    Treatment::factory()->create(['avg_revenue_cents' => -1]);
})->throws(QueryException::class);

it('haelt den Namen je Organisation eindeutig', function (): void {
    Treatment::factory()->create(['name' => 'Botox', 'slug' => 'botox']);
    Treatment::factory()->create(['name' => 'Botox', 'slug' => 'botox-zwei']);
})->throws(QueryException::class);

it('laesst zwei Organisationen denselben Namen fuehren', function (): void {
    Treatment::factory()->create(['name' => 'Botox', 'slug' => 'botox']);

    $andere = organisation('Andere Praxis');

    app(TenantContext::class)->runAs($andere, function (): void {
        Treatment::factory()->create(['name' => 'Botox', 'slug' => 'botox']);
    });

    $anzahl = app(TenantContext::class)->acrossTenants(
        'Test zaehlt ueber Mandanten',
        fn (): int => Treatment::query()->withoutGlobalScopes()->where('name', 'Botox')->count()
    );

    expect($anzahl)->toBe(2);
});

it('liefert als aktive Namen genau die eigenen und aktiven', function (): void {
    $eigene = app(TenantContext::class)->current() ?? organisation();

    Treatment::factory()->create(['name' => 'Botox', 'slug' => 'botox']);
    Treatment::factory()->create(['name' => 'Hyaluron', 'slug' => 'hyaluron']);
    Treatment::factory()->inaktiv()->create(['name' => 'Eingestellt', 'slug' => 'eingestellt']);

    $andere = organisation('Andere Praxis');
    app(TenantContext::class)->runAs($andere, function (): void {
        Treatment::factory()->create(['name' => 'Fremde Behandlung', 'slug' => 'fremd']);
    });

    app(TenantContext::class)->set($eigene);

    // Diese Liste ist zweierlei: was der Agent sagen darf (WP-22) und was nie
    // an Meta gehen darf (WP-32).
    expect(Treatment::aktiveNamen())->toBe(['Botox', 'Hyaluron']);
});

// --- Terminart -------------------------------------------------------------

it('speichert keine Terminart ohne Dauer', function (): void {
    AppointmentType::query()->create([
        'name' => 'Ohne Dauer',
        'slug' => 'ohne-dauer',
    ]);
})->throws(QueryException::class);

it('speichert keine negative Dauer', function (): void {
    AppointmentType::factory()->create(['duration_minutes' => -30]);
})->throws(QueryException::class);

it('laesst Ruestzeiten von null zu', function (): void {
    $art = AppointmentType::factory()->mitRuestzeit(0, 0)->create(['duration_minutes' => 45]);

    expect($art->belegteDauer())->toBe(45)
        ->and($art->angezeigteDauer())->toBe(45);
});

it('speichert keine negative Ruestzeit oder Vorlaufzeit', function (int $feld, string $name): void {
    AppointmentType::factory()->create([$name => -1]);
})->with([
    [0, 'buffer_before_minutes'],
    [1, 'buffer_after_minutes'],
    [2, 'lead_time_hours'],
])->throws(QueryException::class);

it('rechnet die belegte Strecke mit Ruestzeit', function (): void {
    $art = AppointmentType::factory()->mitRuestzeit(15, 15)->create(['duration_minutes' => 60]);

    // Der Kalender belegt 90 Minuten.
    expect($art->belegteDauer())->toBe(90);
});

it('zeigt nur die Dauer an, nicht die Ruestzeit', function (): void {
    $art = AppointmentType::factory()->mitRuestzeit(15, 15)->create(['duration_minutes' => 60]);

    // Der Kontakt liest 60 Minuten. Wer hier 90 anzeigt, laesst ihn eine
    // Viertelstunde zu frueh kommen.
    expect($art->angezeigteDauer())->toBe(60);
});

it('laesst eine Terminart ohne Behandlung zu und bewertet sie mit null', function (): void {
    $ohne = AppointmentType::factory()->create();

    $behandlung = Treatment::factory()->create(['avg_revenue_cents' => 39000]);
    $mit = AppointmentType::factory()->fuer($behandlung)->create();

    expect($ohne->treatment_id)->toBeNull()
        ->and($ohne->umsatzwertCents())->toBe(0)
        ->and($mit->umsatzwertCents())->toBe(39000);
});

// --- Freigaben (V7, V8) ----------------------------------------------------

it('bietet ohne Behandlerfreigabe nichts an', function (): void {
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $art = AppointmentType::factory()->create();

    $art->locations()->attach($standort);

    expect($art->wirdAngebotenVon($behandler, $standort))->toBeFalse();
});

it('bietet ohne Standortangebot nichts an', function (): void {
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $art = AppointmentType::factory()->create();

    $art->practitioners()->attach($behandler);

    expect($art->wirdAngebotenVon($behandler, $standort))->toBeFalse();
});

it('bietet erst mit beiden Freigaben an', function (): void {
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $art = AppointmentType::factory()->create();

    $art->practitioners()->attach($behandler);
    $art->locations()->attach($standort);

    expect($art->wirdAngebotenVon($behandler, $standort))->toBeTrue();
});

it('verhindert eine Freigabe ueber die Organisationsgrenze', function (): void {
    $art = AppointmentType::factory()->create();

    $andere = organisation('Andere Praxis');
    $fremderBehandler = app(TenantContext::class)
        ->runAs($andere, fn (): Practitioner => Practitioner::factory()->create());

    // Der zusammengesetzte Fremdschluessel findet keine passende Zeile.
    $art->practitioners()->attach($fremderBehandler);
})->throws(QueryException::class);

it('bietet eine inaktive Terminart nirgends an', function (): void {
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $art = AppointmentType::factory()->inaktiv()->create();

    $art->practitioners()->attach($behandler);
    $art->locations()->attach($standort);

    expect($art->wirdAngebotenVon($behandler, $standort))->toBeFalse();
});

// --- Vorlaufzeit (V9) ------------------------------------------------------

it('sperrt einen Zeitpunkt innerhalb der Vorlaufzeit', function (): void {
    $art = AppointmentType::factory()->mitVorlauf(48)->create();

    $jetzt = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');

    // Ein Eingriff mit 48 Stunden Vorlauf ist in drei Stunden nicht buchbar.
    expect($art->istBuchbarAm($jetzt->addHours(3), $jetzt))->toBeFalse()
        ->and($art->istBuchbarAm($jetzt->addHours(47), $jetzt))->toBeFalse();
});

it('gibt einen Zeitpunkt jenseits der Vorlaufzeit frei', function (): void {
    $art = AppointmentType::factory()->mitVorlauf(48)->create();

    $jetzt = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');

    expect($art->istBuchbarAm($jetzt->addHours(48), $jetzt))->toBeTrue()
        ->and($art->istBuchbarAm($jetzt->addDays(5), $jetzt))->toBeTrue();
});

it('laesst ohne Vorlaufzeit alles zu, was in der Zukunft liegt', function (): void {
    $art = AppointmentType::factory()->mitVorlauf(0)->create();

    $jetzt = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');

    expect($art->istBuchbarAm($jetzt->addMinutes(5), $jetzt))->toBeTrue();
});
