<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Oeffentlich\Preisangaben;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withoutVite;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterien 1 bis 6 -- die Startseite
|--------------------------------------------------------------------------
|
| **Preise nur aus der geltenden Fassung** (B20): Was im Backoffice unter
| Paket gilt, steht auf der Startseite -- ohne Rueckfall auf die
| Konfiguration.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('zeigt Gaesten unter / die Startseite, ohne Anmeldung und ohne Mandanten', function (): void {
    get('/')
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('oeffentlich/Startseite')
            ->where('auth.user', null)
            ->where('organization', null)
            ->has('demoformular.merkmal')
        );
});

it('zeigt angemeldeten Personen die Startseite mit ihrem Konto, statt sie umzuleiten', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    bezahltesAbo();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    ohneMandant();

    actingAs($inhaberin)->get('/')
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('oeffentlich/Startseite')
            ->where('auth.user.email', $inhaberin->email)
        );
});

it('leitet nach dem Abmelden weiterhin auf die Startseite, und sie laedt', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    bezahltesAbo();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    ohneMandant();

    actingAs($inhaberin)->post(route('logout'))->assertRedirect('/');

    get('/')->assertOk();
});

it('zeigt die Preise der geltenden Paketfassung in Cent', function (): void {
    get('/')->assertInertia(fn ($seite) => $seite
        ->where('preise.grundpreisCent', 79000)
        ->where('preise.einrichtungCent', 149000)
        ->where('preise.testphaseTage', 30)
        ->where('preise.enthalten.nachrichten', 250)
        ->where('preise.enthalten.assistenzlaeufe', 600)
        ->where('preise.enthalten.bilder', 30)
        ->where('preise.aufstockung.preisCent', 5900)
        ->where('preise.aufstockung.nachrichten', 250)
        ->where('preise.aufstockung.assistenzlaeufe', 600)
        ->where('preise.bildpreisCent', 200)
    );
});

it('zeigt nach einer neuen Fassung sofort deren Preise', function (): void {
    neuesPaket(['base_cents' => 89000, 'setup_cents' => 99000]);

    get('/')->assertInertia(fn ($seite) => $seite
        ->where('preise.grundpreisCent', 89000)
        ->where('preise.einrichtungCent', 99000)
    );
});

it('zeigt eine Fassung, die bei Stripe noch angelegt wird, noch nicht', function (): void {
    $geltend = PlanVersion::query()->whereNotNull('activated_at')->orderByDesc('number')->firstOrFail();

    $ausstehend = new PlanVersion;
    $ausstehend->forceFill([
        ...collect($geltend->getAttributes())->except(['id', 'number', 'created_at', 'updated_at', 'activated_at'])->all(),
        'reason' => 'Test',
        'base_cents' => 99900,
        'number' => $geltend->number + 1,
        'stripe_state' => PlanVersion::AUSSTEHEND,
        'activated_at' => null,
    ]);
    $ausstehend->save();

    get('/')->assertInertia(fn ($seite) => $seite->where('preise.grundpreisCent', 79000));
});

it('blendet die Preise aus, wenn keine Fassung gilt', function (): void {
    // Die Migration legt Fassung 1 an, ein Trigger verhindert das Loeschen --
    // ohne Fassung gibt es die Startseite also nur im Gedankenexperiment. Sie
    // soll dort nicht abstuerzen.
    expect(Preisangaben::aus(null))->toBeNull();
});

it('laesst eine Praxis mit gesperrtem Abo Startseite, Impressum und Datenschutzerklaerung sehen', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Unpaid;
    $abo->stripe_subscription_id = 'sub_1';
    $abo->save();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    ohneMandant();

    // Die Gegenprobe: das Abo sperrt wirklich.
    actingAs($inhaberin)->get(route('dashboard'))->assertRedirect(route('abo.edit'));

    foreach (['/', '/impressum', '/datenschutzerklaerung'] as $pfad) {
        actingAs($inhaberin)->get($pfad)->assertOk();
    }
});

it('liefert Titel, Beschreibung, kanonische Adresse und Open-Graph-Angaben im ersten HTML', function (): void {
    withoutVite();
    config(['app.url' => 'https://mrs-beauty.ai']);

    $html = get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<title inertia>Werbung, Kommunikation und Termine für ästhetische Praxen · Mrs. Beauty</title>')
        ->toContain('<meta name="description" content="')
        ->toContain('<link rel="canonical" href="https://mrs-beauty.ai/">')
        ->toContain('<meta property="og:title" content="Werbung, Kommunikation und Termine für ästhetische Praxen · Mrs. Beauty">')
        ->toContain('<meta property="og:url" content="https://mrs-beauty.ai/">')
        ->toContain('<meta property="og:image" content="https://mrs-beauty.ai/icon-512.png">')
        ->toContain('<meta property="og:locale" content="de_DE">');

    // **Ohne `inertia`-Attribut** -- sonst entfernt der Kopf-Verwalter sie
    // beim ersten Seitenwechsel, und wer mit ausgefuehrtem Skript liest,
    // sieht keine.
    expect($html)->not->toMatch('/<meta[^>]+name="description"[^>]*\binertia\b/');
});

it('baut die kanonische Adresse aus app.url, nicht aus dem angefragten Host', function (): void {
    withoutVite();
    config(['app.url' => 'https://mrs-beauty.ai']);

    $html = get('http://vorschau-1234.laravel.cloud/impressum')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="https://mrs-beauty.ai/impressum">')
        ->and($html)->not->toContain('content="http://vorschau-1234.laravel.cloud');
});
