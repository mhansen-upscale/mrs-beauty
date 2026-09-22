<?php

declare(strict_types=1);

use App\Models\Organization;
use Carbon\CarbonImmutable;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| Wer die Behandlung macht, steht auf der Buchungsseite
|--------------------------------------------------------------------------
|
| Ein Name und ein Gesicht nehmen einer Buchung mehr Unsicherheit als jeder
| Beschreibungstext. Preis und Beschreibung bleiben dagegen weiterhin weg --
| beides wartet auf die HWG-Pruefung (WP-30).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('nennt zu jeder Terminart die freigegebenen Behandler', function (): void {
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $szenario = new Szenario;

    $szenario->aufbau->art->is_public = true;
    $szenario->aufbau->art->save();

    $behandler = $szenario->aufbau->behandler;

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('appointmentTypes.0.practitioners.0.uuid', (string) $behandler->uuid)
            ->where('appointmentTypes.0.practitioners.0.name', $behandler->name())
            ->where('appointmentTypes.0.practitioners.0.avatar_url', null)
            ->where('appointmentTypes.0.practitioners.0.initials', $behandler->initialen())
        );
});

it('nimmt eine Terminart aus dem Angebot, wenn niemand sie mehr macht', function (): void {
    // Die Gegenprobe: wer nicht mehr da ist, steht auch nicht mehr auf der
    // oeffentlichen Seite -- und eine Terminart, die dadurch niemanden mehr
    // hat, wird gar nicht erst angeboten. Buchbar waere sie ohnehin nicht.
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $szenario = new Szenario;

    $szenario->aufbau->art->is_public = true;
    $szenario->aufbau->art->save();

    $szenario->aufbau->behandler->is_active = false;
    $szenario->aufbau->behandler->save();

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('appointmentTypes', []));
});
