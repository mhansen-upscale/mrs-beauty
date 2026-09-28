<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\DemoRequestStatus;
use App\Models\AuditLog;
use App\Models\DemoRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterien 20 bis 23 -- Demo-Anfragen im Backoffice
|--------------------------------------------------------------------------
|
| Die Anfragen gehoeren keiner Praxis. Wer sie bearbeitet, steht im
| Betreiberprotokoll -- ohne Organisation und ohne eine Angabe der Anfrage
| (C5).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** @return Collection<int, AuditLog> */
function demoanfragenProtokoll(AuditEvent $ereignis): Collection
{
    return AuditLog::query()->withoutGlobalScopes()->where('event', $ereignis->value)->get();
}

it('zeigt Super-Admin und Customer Success die Anfragen, die neueste zuerst', function (): void {
    DemoRequest::factory()->create(['practice_name' => 'Aeltere Praxis', 'created_at' => CarbonImmutable::now()->subDays(2)]);
    DemoRequest::factory()->create(['practice_name' => 'Neuere Praxis', 'created_at' => CarbonImmutable::now()->subHour()]);

    foreach ([User::factory()->superAdmin()->create(), User::factory()->customerSuccess()->create()] as $betreiber) {
        actingAs($betreiber)->get(route('backoffice.demoanfragen'))
            ->assertOk()
            ->assertInertia(fn ($seite) => $seite
                ->component('backoffice/Demoanfragen')
                ->has('anfragen', 2)
                ->where('anfragen.0.praxis', 'Neuere Praxis')
                ->where('anfragen.1.praxis', 'Aeltere Praxis')
                ->where('anfragen.0.status', DemoRequestStatus::New->value)
            );
    }

    actingAs(User::factory()->finanzen()->create())->get(route('backoffice.demoanfragen'))->assertForbidden();
});

it('filtert die Anfragen auf dem Server nach Status', function (): void {
    DemoRequest::factory()->create(['practice_name' => 'Offene Praxis']);
    DemoRequest::factory()->erledigt()->create(['practice_name' => 'Erledigte Praxis']);

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.demoanfragen', ['status' => DemoRequestStatus::Closed->value]))
        ->assertInertia(fn ($seite) => $seite
            ->has('anfragen', 1)
            ->where('anfragen.0.praxis', 'Erledigte Praxis')
            ->where('filter.status', DemoRequestStatus::Closed->value)
        );
});

it('setzt den Status und protokolliert ihn ohne Organisation und ohne Kontaktdaten', function (): void {
    $anfrage = DemoRequest::factory()->create(['name' => 'Dr. Jana Berger', 'email' => 'jana@praxis.test']);
    $betreiber = User::factory()->customerSuccess()->create();

    actingAs($betreiber)
        ->patch(route('backoffice.demoanfragen.status', ['demoanfrage' => $anfrage->uuid]), ['status' => DemoRequestStatus::Contacted->value])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $anfrage->refresh();

    expect($anfrage->status)->toBe(DemoRequestStatus::Contacted)
        ->and($anfrage->status_changed_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    $eintrag = demoanfragenProtokoll(AuditEvent::DemoRequestStatusChanged)->sole();

    expect($eintrag->getAttributes()['organization_id'])->toBeNull()
        ->and($eintrag->actor_user_id)->toBe($betreiber->getKey())
        ->and($eintrag->context)->toBe(['status' => DemoRequestStatus::Contacted->value])
        ->and(json_encode($eintrag->getAttributes(), JSON_INVALID_UTF8_SUBSTITUTE))->not->toContain('Jana')
        ->and(json_encode($eintrag->getAttributes(), JSON_INVALID_UTF8_SUBSTITUTE))->not->toContain('jana@praxis.test');
});

it('lehnt einen unbekannten Status ab', function (): void {
    $anfrage = DemoRequest::factory()->create();

    actingAs(User::factory()->superAdmin()->create())
        ->patch(route('backoffice.demoanfragen.status', ['demoanfrage' => $anfrage->uuid]), ['status' => 'gewonnen'])
        ->assertSessionHasErrors('status');

    expect($anfrage->refresh()->status)->toBe(DemoRequestStatus::New);
});

it('loescht eine Anfrage nur mit dem eigenen Passwort und protokolliert es', function (): void {
    $anfrage = DemoRequest::factory()->create();
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)
        ->delete(route('backoffice.demoanfragen.loeschen', ['demoanfrage' => $anfrage->uuid]), ['current_password' => 'falsch'])
        ->assertSessionHasErrors('current_password');

    expect(DemoRequest::query()->count())->toBe(1)
        ->and(demoanfragenProtokoll(AuditEvent::DemoRequestDeleted))->toBeEmpty();

    actingAs($betreiber)
        ->delete(route('backoffice.demoanfragen.loeschen', ['demoanfrage' => $anfrage->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(DemoRequest::query()->count())->toBe(0);

    $eintrag = demoanfragenProtokoll(AuditEvent::DemoRequestDeleted)->sole();

    expect($eintrag->getAttributes()['organization_id'])->toBeNull()
        ->and($eintrag->actor_user_id)->toBe($betreiber->getKey());
});

it('erreicht die Liste nur angemeldet', function (): void {
    get(route('backoffice.demoanfragen'))->assertRedirect(route('backoffice.anmelden'));
});
