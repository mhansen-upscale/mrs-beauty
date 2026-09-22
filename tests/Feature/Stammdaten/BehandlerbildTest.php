<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Practitioner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| Das Portrait eines Behandlers
|--------------------------------------------------------------------------
|
| Es liegt **unverschluesselt auf einer oeffentlichen Platte** -- dieselbe
| Entscheidung wie beim Namen: die Praxis veroeffentlicht es selbst auf ihrer
| Buchungsseite. Regel 3 verlangt Verschluesselung fuer personenbezogene
| Daten der Patienten, nicht fuer das, was die Praxis von sich aus zeigt.
|
| Was die Regel hier trotzdem fordert: dass nichts liegenbleibt. Ein Bild,
| das ersetzt oder entfernt wird, verschwindet auch vom Speicher.
|
*/

beforeEach(function (): void {
    Storage::fake('public');
});

it('nimmt ein Portrait entgegen und zeigt es an', function (): void {
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer)
        ->post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
            'avatar' => UploadedFile::fake()->image('portrait.jpg', 400, 400),
        ])
        ->assertSessionHasNoErrors();

    $pfad = $behandler->fresh()?->avatar_path;

    expect($pfad)->toBeString();
    Storage::disk('public')->assertExists((string) $pfad);
});

it('loescht das alte Bild, wenn ein neues kommt', function (): void {
    // Sonst sammelt sich auf dem Speicher jedes je hochgeladene Portrait --
    // und zwar dauerhaft, denn niemand raeumt dort je auf.
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer);

    post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
        'avatar' => UploadedFile::fake()->image('erst.jpg', 400, 400),
    ]);

    $erst = (string) $behandler->fresh()?->avatar_path;

    post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
        'avatar' => UploadedFile::fake()->image('zweit.jpg', 400, 400),
    ]);

    $zweit = (string) $behandler->fresh()?->avatar_path;

    expect($zweit)->not->toBe($erst);
    Storage::disk('public')->assertMissing($erst);
    Storage::disk('public')->assertExists($zweit);
});

it('entfernt Bild und Datei auf Wunsch', function (): void {
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer);

    post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
        'avatar' => UploadedFile::fake()->image('portrait.jpg', 400, 400),
    ]);

    $pfad = (string) $behandler->fresh()?->avatar_path;

    delete(route('practitioners.avatar.destroy', ['practitioner' => $behandler->uuid]))
        ->assertSessionHasNoErrors();

    expect($behandler->fresh()?->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($pfad);
});

it('weist alles zurueck, was kein Bild ist', function (): void {
    // Das Feld ist oeffentlich sichtbar eingebunden. Ein hochgeladenes
    // Skript waere dann eine Datei unter unserer Adresse.
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer)
        ->post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
            'avatar' => UploadedFile::fake()->create('schadcode.svg', 12, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('avatar');

    expect($behandler->fresh()?->avatar_path)->toBeNull();
});

it('weist ein zu kleines Bild zurueck', function (): void {
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer)
        ->post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
            'avatar' => UploadedFile::fake()->image('winzig.jpg', 80, 80),
        ])
        ->assertSessionHasErrors('avatar');
});

it('laesst den Empfang kein Portrait aendern', function (): void {
    // Stammdaten pflegt, wer die Praxis fuehrt.
    $organisation = alsMandant(organisation('Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($benutzer)
        ->post(route('practitioners.avatar.store', ['practitioner' => $behandler->uuid]), [
            'avatar' => UploadedFile::fake()->image('portrait.jpg', 400, 400),
        ])
        ->assertForbidden();
});
