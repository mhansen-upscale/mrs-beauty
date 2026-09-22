<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\EncryptionKey;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\KeyRing;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| WP-04, Abnahmekriterien 18 und 19
|--------------------------------------------------------------------------
*/

beforeEach(fn () => ohneMandant());

it('haelt die Registrierung geschlossen, solange der Schalter aus ist', function (): void {
    config()->set('mrs.registration.self_service', false);

    get(route('register'))->assertNotFound();

    post(route('register'), [
        'organization' => 'Praxis Nord',
        'name' => 'Inhaberin',
        'email' => 'inhaberin@praxis.de',
        'password' => 'ein-langes-passwort',
        'password_confirmation' => 'ein-langes-passwort',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0);
});

it('legt bei offener Registrierung Organisation, Schluessel und Inhaberin an', function (): void {
    config()->set('mrs.registration.self_service', true);

    post(route('register'), [
        'organization' => 'Praxis Nord',
        'name' => 'Inhaberin',
        'email' => 'inhaberin@praxis.de',
        'password' => 'ein-langes-passwort',
        'password_confirmation' => 'ein-langes-passwort',
    ])->assertRedirect(route('dashboard', absolute: false));

    $organisation = Organization::query()->firstOrFail();
    $benutzer = User::query()->firstOrFail();

    expect($organisation->name)->toBe('Praxis Nord')
        ->and($organisation->slug)->toBe('praxis-nord')
        ->and($benutzer->organization_id)->toBe($organisation->getKey())
        ->and($benutzer->role)->toBe(Role::Owner)
        ->and(EncryptionKey::query()->where('organization_id', $organisation->getKey())->exists())->toBeTrue();

    // Und der Schluessel funktioniert tatsaechlich.
    expect(app(KeyRing::class)->for($organisation->getKey())->dataEncryptionKey)
        ->toBeString()
        ->and(strlen(app(KeyRing::class)->for($organisation->getKey())->dataEncryptionKey))->toBe(32);
});

it('vergibt einen freien Slug, wenn der Name schon vergeben ist', function (): void {
    config()->set('mrs.registration.self_service', true);
    Organization::factory()->create(['name' => 'Praxis Nord', 'slug' => 'praxis-nord']);

    post(route('register'), [
        'organization' => 'Praxis Nord',
        'name' => 'Zweite',
        'email' => 'zweite@praxis.de',
        'password' => 'ein-langes-passwort',
        'password_confirmation' => 'ein-langes-passwort',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect(Organization::query()->where('slug', 'praxis-nord')->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(2);
});

it('legt nichts an, wenn ein Schritt fehlschlaegt', function (): void {
    config()->set('mrs.registration.self_service', true);

    // Der Schluesselsatz laesst sich nicht anlegen. Kein DDL: ein DROP TABLE
    // loest in MySQL ein implizites Commit aus und beendet damit die
    // Transaktion des Tests gleich mit.
    app()->instance(KeyRing::class, new class extends KeyRing
    {
        public function issue(Organization $organization): EncryptionKey
        {
            throw new RuntimeException('Schluesselsatz fehlgeschlagen');
        }
    });

    try {
        post(route('register'), [
            'organization' => 'Praxis Nord',
            'name' => 'Inhaberin',
            'email' => 'inhaberin@praxis.de',
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ]);
    } catch (Throwable) {
        // erwartet
    }

    // Eine Organisation ohne Schluesselsatz waere von der ersten Sekunde an
    // unbrauchbar. Also darf auch keine entstanden sein.
    expect(Organization::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});
