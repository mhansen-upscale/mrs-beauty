<?php

declare(strict_types=1);

use App\Backoffice\Installationskennzahlen;
use App\Enums\AppointmentStatus;
use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use App\Enums\NotificationKind;
use App\Enums\Role;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\ChannelConnection;
use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\User;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\MailAufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-36, Abnahmekriterien 21 bis 25 -- nur ueber das Postfach der Praxis
|--------------------------------------------------------------------------
|
| **Kein Rueckfall auf die Plattform** (B22). Ohne eigenes Postfach geht
| keine Mail an eine Patientin hinaus -- und das Produkt sagt es, statt still
| unter fremdem Namen zu verschicken.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('verschickt ohne Postfach keine Terminmail, auch nicht ueber die Plattform (AK 22)', function (): void {
    alsMandant(organisation('Praxis ohne Postfach'));
    bezahltesAbo();
    $attrappe = postfachAttrappe();

    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    $bestaetigung = $termin->notifications()->where('kind', NotificationKind::Confirmation->value)->firstOrFail();

    expect($bestaetigung->sent_at)->toBeNull()
        ->and($bestaetigung->failed_at)->not->toBeNull()
        ->and($bestaetigung->failure)->toBe('no_mailer')
        ->and(versandteMails($attrappe))->toBeEmpty();

    // Und nichts ueber den Mailer der Plattform (MAIL_MAILER=array).
    $plattform = app('mail.manager')->mailer()->getSymfonyTransport();
    assert($plattform instanceof ArrayTransport);

    expect($plattform->messages())->toHaveCount(0);
});

it('verschickt ohne Absenderadresse ebenso wenig', function (): void {
    Notification::fake();
    alsMandant(organisation());
    bezahltesAbo();
    praxispostfach()->forceFill(['sender_id' => null])->save();

    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    expect($termin->notifications()->where('kind', NotificationKind::Confirmation->value)->firstOrFail()->failure)->toBe('no_mailer');

    Notification::assertNothingSent();
});

it('laesst eine Antwort aus dem Posteingang ohne Postfach sichtbar scheitern (AK 23)', function (): void {
    $aufbau = new MailAufbau;
    $aufbau->verbindung->forceFill(['smtp_host' => null, 'smtp_port' => null])->save();

    $identitaet = ChannelIdentity::create(['channel' => ChannelType::Email, 'external_id' => 'annika@example.test']);
    $konversation = app(Konversationen::class)->fuer($identitaet);
    app(Konversationen::class)->nimmAuf($konversation, 'abc-1@example.test', 'Guten Tag', null, null, 'Frage zum Termin');

    app(Nachrichtenversand::class)->stelleEin($konversation->fresh() ?? $konversation, 'Antwort');

    $antwort = Message::query()->where('direction', 'outbound')->firstOrFail();

    expect($antwort->status)->toBe(MessageStatus::Failed)
        ->and($antwort->failure)->toBe('no_mailer')
        ->and(versandteMails($aufbau->attrappe))->toBeEmpty();
});

it('zeigt auf dem Dashboard, dass keine Patientenmails hinausgehen (AK 25)', function (): void {
    $praxis = alsMandant(organisation('Praxis ohne Postfach'));
    bezahltesAbo();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    $szenario = new Szenario;
    app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());
    ohneMandant();

    actingAs($inhaberin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('betrieb.mailversand.bereit', false)
            ->where('betrieb.mailversand.fehlgeschlagen', 1));
});

it('meldet mit Postfach keinen Hinweis', function (): void {
    $praxis = alsMandant(organisation());
    bezahltesAbo();
    praxispostfach();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    ohneMandant();

    actingAs($inhaberin)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('betrieb.mailversand.bereit', true)
            ->where('betrieb.mailversand.fehlgeschlagen', 0));
});

it('zaehlt im Backoffice die Praxen ohne Postfach (AK 25)', function (): void {
    alsMandant(organisation('Mit Postfach'));
    praxispostfach();

    alsMandant(organisation('Ohne Postfach'));
    alsMandant(organisation('Nur Eingang'));
    (new ChannelConnection)->forceFill([
        'channel' => ChannelType::Email,
        'status' => 'active',
        'external_id' => 'nur-eingang@inbound.mrs-beauty.test',
        'sender_id' => 'praxis@nur-eingang.test',
    ])->save();

    ohneMandant();

    expect(app(Installationskennzahlen::class)->jetzt()['betrieb']['praxenOhnePostfach'])->toBe(2);
});
