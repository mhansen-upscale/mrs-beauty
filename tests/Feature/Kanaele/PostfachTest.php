<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Jobs\PostfachPruefen;
use App\Kanaele\Email\Postfach;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
|--------------------------------------------------------------------------
| Das Postfach der Praxis (WP-20b)
|--------------------------------------------------------------------------
|
| Ohne eigene Zugangsdaten verschickt die Plattform -- mit der Adresse der
| Praxis im Absender, aber aus fremder Infrastruktur. Das besteht SPF und
| DKIM nur, wenn jemand die DNS-Eintraege gesetzt hat. Mit eigenem Postfach
| geht die Mail denselben Weg wie jede andere Mail der Praxis.
|
*/

/**
 * @param  array<string, string>  $ueberschreiben
 * @return array<string, string>
 */
function postfachdaten(array $ueberschreiben = []): array
{
    return array_merge([
        'absender' => 'praxis@demo-praxis.de',
        'anzeigename' => 'Demo-Praxis',
        'smtp_host' => 'smtp.demo-praxis.de',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => 'praxis@demo-praxis.de',
        'smtp_password' => 'app-passwort',
    ], $ueberschreiben);
}

function postfachinhaberin(): User
{
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Demo-Praxis', 'slug' => 'demo-praxis']));

    return User::factory()->fuer($organisation, Role::Owner)->create();
}

it('schlaegt eine Eingangsadresse aus dem Kennzeichen der Praxis vor', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer)
        ->get(route('postfach.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/Postfach')
            ->where('eingang', 'demo-praxis@'.config('mrs.channels.email.inbound_domain'))
            ->where('eingerichtet', false)
        );
});

it('legt beim ersten Speichern die Kanalverbindung an', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer)->put(route('postfach.update'), postfachdaten())->assertSessionHasNoErrors();

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->channel)->toBe(ChannelType::Email)
        ->and($verbindung->external_id)->toBe('demo-praxis@'.config('mrs.channels.email.inbound_domain'))
        ->and($verbindung->sender_id)->toBe('praxis@demo-praxis.de')
        ->and($verbindung->smtp_host)->toBe('smtp.demo-praxis.de')
        ->and($verbindung->smtp_port)->toBe(587)
        ->and($verbindung->hatEigenesPostfach())->toBeTrue();
});

it('behaelt die Eingangsadresse beim zweiten Speichern', function (): void {
    // Sie steht auf Briefbogen und in Weiterleitungsregeln -- eine Adresse,
    // die sich aendert, verliert Post.
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());
    $erste = ChannelConnection::query()->firstOrFail()->external_id;

    put(route('postfach.update'), postfachdaten(['absender' => 'info@demo-praxis.de']));

    expect(ChannelConnection::query()->firstOrFail()->external_id)->toBe($erste);
});

it('legt Benutzername und Passwort verschluesselt ab', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer)->put(route('postfach.update'), postfachdaten());

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->smtp_password)->toBe('app-passwort');

    $roh = DB::table('channel_connections')->where('id', $verbindung->getRawOriginal('id'))->first();

    expect((string) $roh?->smtp_password)->not->toContain('app-passwort');
    expect((string) $roh?->smtp_username)->not->toContain('demo-praxis.de');
});

it('gibt das Passwort nie an die Oberflaeche zurueck', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());

    get(route('postfach.edit'))
        ->assertInertia(fn ($seite) => $seite->where('smtp.gesetzt', true))
        ->assertDontSee('app-passwort');
});

it('laesst ein leeres Passwortfeld das gespeicherte stehen', function (): void {
    // Sonst loescht jedes Speichern der uebrigen Angaben das Passwort mit.
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());
    put(route('postfach.update'), postfachdaten(['smtp_password' => '', 'anzeigename' => 'Praxis Dr. Sauer']));

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->smtp_password)->toBe('app-passwort')
        ->and($verbindung->display_name)->toBe('Praxis Dr. Sauer');
});

it('raeumt die Zugangsdaten ab, wenn der Server entfernt wird', function (): void {
    // Ein Passwort ohne Server ist ein Geheimnis ohne Zweck.
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());
    put(route('postfach.update'), postfachdaten(['smtp_host' => '', 'smtp_password' => '']));

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->smtp_host)->toBeNull()
        ->and($verbindung->smtp_password)->toBeNull()
        ->and($verbindung->smtp_username)->toBeNull()
        ->and($verbindung->hatEigenesPostfach())->toBeFalse();
});

it('weist eine Absenderadresse zurueck, die keine ist', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    from(route('postfach.edit'))
        ->put(route('postfach.update'), postfachdaten(['absender' => 'keine-adresse']))
        ->assertSessionHasErrors('absender');

    expect(ChannelConnection::query()->count())->toBe(0);
});

it('setzt die Pruefung zurueck, sobald sich etwas aendert', function (): void {
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());

    $verbindung = ChannelConnection::query()->firstOrFail();
    $verbindung->verified_at = now();
    $verbindung->save();

    put(route('postfach.update'), postfachdaten(['smtp_host' => 'smtp2.demo-praxis.de']));

    expect(ChannelConnection::query()->firstOrFail()->verified_at)->toBeNull();
});

it('reiht die Probemail ein, statt sie im Anfragezyklus zu schicken', function (): void {
    // Regel 4: ein Mailserver, der nicht antwortet, laesst sonst diese Seite
    // haengen.
    Queue::fake();
    $benutzer = postfachinhaberin();

    actingAs($benutzer);

    put(route('postfach.update'), postfachdaten());

    post(route('postfach.pruefen'))->assertSessionHasNoErrors();

    Queue::assertPushed(PostfachPruefen::class);
});

it('vermerkt einen fehlgeschlagenen Versand an der Verbindung, ohne Klartext', function (): void {
    $benutzer = postfachinhaberin();
    actingAs($benutzer)->put(route('postfach.update'), postfachdaten());

    // Ein Server, den es nicht gibt.
    $verbindung = ChannelConnection::query()->firstOrFail();
    $verbindung->smtp_host = '127.0.0.1';
    $verbindung->smtp_port = 1;
    $verbindung->save();

    $organisation = $verbindung->organization;

    expect($organisation)->toBeInstanceOf(Organization::class);

    (new PostfachPruefen((string) ($organisation instanceof Organization ? $organisation->uuid : ''), 'inhaberin@demo.test'))
        ->handle(app(TenantContext::class), app(Postfach::class));

    $frisch = ChannelConnection::query()->firstOrFail();

    expect($frisch->status)->toBe(ConnectionStatus::Expired)
        ->and($frisch->last_error)->toBe('smtp_failed')
        ->and($frisch->verified_at)->toBeNull();
});

it('laesst den Empfang das Postfach nicht aendern', function (): void {
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Demo-Praxis', 'slug' => 'demo-praxis']));
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)->get(route('postfach.edit'))->assertForbidden();
    actingAs($benutzer)->put(route('postfach.update'), postfachdaten())->assertForbidden();
});

/* Der Versandweg ---------------------------------------------------------- */

it('schickt ueber den Server der Praxis, wenn einer hinterlegt ist', function (): void {
    $benutzer = postfachinhaberin();
    actingAs($benutzer)->put(route('postfach.update'), postfachdaten());

    $mailer = app(Postfach::class)->mailer(ChannelConnection::query()->firstOrFail());

    expect((string) $mailer->getSymfonyTransport())->toContain('smtp.demo-praxis.de');
});

it('faellt ohne eigenen Server auf den Versand der Plattform zurueck', function (): void {
    // Kein Notbehelf: er haelt eine Praxis arbeitsfaehig, die gerade erst
    // anfaengt -- mit ihrer Adresse im Absender.
    $benutzer = postfachinhaberin();
    actingAs($benutzer)->put(route('postfach.update'), postfachdaten(['smtp_host' => '', 'smtp_password' => '']));

    $mailer = app(Postfach::class)->mailer(ChannelConnection::query()->firstOrFail());

    expect((string) $mailer->getSymfonyTransport())->not->toContain('smtp.demo-praxis.de');
});
