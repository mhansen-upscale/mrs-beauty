<?php

declare(strict_types=1);

use App\Enums\Ampel;
use App\Enums\Role;
use App\Kanaele\WhatsApp\Templateabgleich;
use App\Models\ComplianceCheck;
use App\Models\Organization;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-30 -- die Pruefgegenstaende Buchungsseite und Template
|--------------------------------------------------------------------------
|
| "Templates und Buchungsseite sind als Pruefgegenstaende vorgesehen, aber
| noch nicht angeschlossen." Und WP-12: "Preis und Behandlungsbeschreibung
| bleiben aus, bis WP-30 sie pruefen kann." Beides ist seit dem 26.09.2026
| geschlossen.
|
| **Die Buchungsseite ist Publikumswerbung.** Was dort steht, laeuft durch
| dieselbe Pruefung wie eine Anzeige -- und erscheint wie eine Anzeige nur
| bei Gruen oder nach einer begruendeten Uebersteuerung (C3). Gelb allein
| genuegt nicht: es heisst, jemand muss hinsehen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Eine Praxis mit oeffentlicher Terminart, die zu einer Behandlung gehoert.
 *
 * @return array{Organization, Treatment, User}
 */
function praxisMitBehandlung(): array
{
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Praxis', 'slug' => 'demo-praxis']));
    $szenario = new Szenario;

    $behandlung = Treatment::factory()->create(['name' => 'Hyaluron', 'description' => null, 'price_from_cents' => null, 'price_to_cents' => null]);

    $szenario->aufbau->art->is_public = true;
    $szenario->aufbau->art->treatment_id = $behandlung->getKey();
    $szenario->aufbau->art->save();

    return [$organisation, $behandlung, User::factory()->fuer($organisation, Role::Owner)->create()];
}

/** Speichert Beschreibung und Preis ueber den Katalog -- den Weg der Praxis. */
function beschreibe(User $wer, Treatment $behandlung, string $text, ?int $ab = 25000): void
{
    actingAs($wer)
        ->patch(route('treatments.update', ['treatment' => $behandlung->uuid]), [
            'name' => $behandlung->name,
            'slug' => $behandlung->slug,
            'description' => $text,
            'category' => $behandlung->category,
            'price_from_cents' => $ab,
            'price_to_cents' => null,
            'avg_revenue_cents' => $behandlung->avg_revenue_cents,
            'all_practitioners' => true,
            'practitioners' => [],
        ])
        ->assertSessionHasNoErrors();
}

/**
 * Was die Buchungsseite zur ersten Terminart zeigt.
 *
 * @return array<string, mixed>
 */
function buchungsseite(): array
{
    ohneMandant();

    $seite = get('/buchen/demo-praxis')->assertOk()->viewData('page');

    return (array) data_get($seite, 'props.appointmentTypes.0');
}

const UNBEDENKLICH = 'Ablauf: Vorgespräch, Behandlung, Nachkontrolle. Über Risiken sprechen wir vorab persönlich mit Ihnen.';

it('prueft eine Behandlungsbeschreibung beim Speichern', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, UNBEDENKLICH);

    $pruefung = ComplianceCheck::query()->where('checkable_type', Treatment::class)->firstOrFail();

    expect($pruefung->result)->toBe(Ampel::Gruen)
        ->and($pruefung->checkable_id)->toBe($behandlung->getKey());
});

it('zeigt eine unbedenkliche Beschreibung samt Preis auf der Buchungsseite', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, UNBEDENKLICH, ab: 25000);

    $art = buchungsseite();

    expect($art['description'])->toBe(UNBEDENKLICH)
        ->and($art['price'])->toBe('ab 250 €');
});

it('zeigt eine beanstandete Beschreibung nicht', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, 'Garantiert schmerzfrei und ohne Ausfallzeit — die beste Praxis der Stadt.');

    $art = buchungsseite();

    // Auch der Preis nicht: er stand im selben gepruften Text.
    expect($art['description'])->toBeNull()
        ->and($art['price'])->toBeNull();
});

it('zeigt eine gelbe Beschreibung nicht -- gelb heisst hinsehen', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    // Eine Unterspritzung ohne Risikohinweis: gelb, nicht rot.
    beschreibe($inhaberin, $behandlung, 'Unterspritzung mit Hyaluron in unserer Praxis.');

    expect(ComplianceCheck::query()->firstOrFail()->result)->toBe(Ampel::Gelb)
        ->and(buchungsseite()['description'])->toBeNull();
});

it('zeigt eine Beschreibung nach begruendeter Uebersteuerung', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, 'Unterspritzung mit Hyaluron in unserer Praxis.');

    actingAs($inhaberin)
        ->post(route('treatments.hwg.uebersteuern', ['treatment' => $behandlung->uuid]), [
            'grund' => 'Der Risikohinweis steht direkt darunter auf der Buchungsseite im Rahmen.',
        ])
        ->assertSessionHasNoErrors();

    expect(buchungsseite()['description'])->toBe('Unterspritzung mit Hyaluron in unserer Praxis.');
});

it('verlangt fuer die Uebersteuerung eine Begruendung', function (): void {
    [, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, 'Unterspritzung mit Hyaluron in unserer Praxis.');

    actingAs($inhaberin)
        ->post(route('treatments.hwg.uebersteuern', ['treatment' => $behandlung->uuid]), ['grund' => ''])
        ->assertSessionHasErrors('grund');

    expect(buchungsseite()['description'])->toBeNull();
});

it('zeigt nichts, was nach der Pruefung geaendert wurde', function (): void {
    [$organisation, $behandlung, $inhaberin] = praxisMitBehandlung();

    beschreibe($inhaberin, $behandlung, UNBEDENKLICH);

    // Am Katalog vorbei -- ein Seeder, eine Konsole: die Pruefung galt dem
    // alten Text.
    travelTo(CarbonImmutable::parse('2027-01-12 09:00:00', 'UTC'));
    alsMandant($organisation);
    $behandlung->refresh()->update(['description' => 'Garantiert schmerzfrei.']);

    expect(buchungsseite()['description'])->toBeNull();
});

it('prueft ein Template beim Abgleich', function (): void {
    $aufbau = new WhatsAppAufbau;

    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
        'id' => '1',
        'name' => 'herbstaktion',
        'language' => 'de',
        'status' => 'APPROVED',
        'category' => 'MARKETING',
        'components' => [['type' => 'BODY', 'text' => 'Garantiert schmerzfrei: jetzt Termin sichern, {{1}}!']],
    ]]])]);

    app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    $template = WhatsAppTemplate::query()->firstOrFail();

    expect($template->pruefung()->first()?->result)->toBe(Ampel::Rot);
});
