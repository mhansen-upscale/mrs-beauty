<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Audit\ImpersonationContext;
use App\Audit\Supportfreigabe;
use App\Audit\SupportPinAbgelehnt;
use App\Enums\AuditEvent;
use App\Enums\Freigabeweg;
use App\Enums\ImpersonationMode;
use App\Enums\Role;
use App\Http\Controllers\Audit\SupportPinController;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\SupportPin;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

/*
|--------------------------------------------------------------------------
| WP-34b, Abnahmekriterien 1 bis 17
|--------------------------------------------------------------------------
|
| Die Praxis gibt frei, nicht der Betreiber. Die PIN ist ein zweiter Weg zu
| derselben Freigabe, kein Generalschluessel (C15).
|
*/

/**
 * Eine Praxis mit Inhaberin und einer laufenden, maskierten Sitzung.
 *
 * @return array{0: Organization, 1: User, 2: User, 3: ImpersonationSession}
 */
function pinAufbau(string $name = 'Praxis'): array
{
    $praxis = alsMandant(organisation($name));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');

    return [$praxis, $inhaberin, $betreiber, $sitzung];
}

/**
 * Die Ereignisse, die im Protokoll einer Praxis stehen.
 *
 * @return list<string>
 */
function praxisereignisse(Organization $praxis): array
{
    /** @var list<string> */
    return AuditLog::query()
        ->withoutGlobalScopes()
        ->where('organization_id', $praxis->getKey())
        ->get()
        ->map(fn (AuditLog $eintrag): string => $eintrag->event->value)
        ->values()
        ->all();
}

/** Loest ab und gibt die Ablehnung zurueck, statt sie zu werfen. */
function pinAblehnung(ImpersonationSession $sitzung, User $betreiber, string $pin): ?SupportPinAbgelehnt
{
    try {
        app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);
    } catch (SupportPinAbgelehnt $ablehnung) {
        return $ablehnung;
    }

    return null;
}

/** Eine PIN, die sicher nicht die gegebene ist. */
function falschePin(string $pin): string
{
    return str_pad((string) (((int) $pin + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
}

/* Erzeugen ------------------------------------------------------------------ */

it('zeigt der Inhaberin die PIN genau einmal', function (): void {
    [, $inhaberin] = pinAufbau();

    actingAs($inhaberin)->post(route('support-pin.store'))->assertRedirect();

    // Die Weiterleitung legt die PIN fuer genau eine Anfrage ab (AK 1).
    $erste = actingAs($inhaberin)->get(route('team.index'))->assertOk();
    $pin = $erste->inertiaProps('neuePin.pin');

    expect($pin)->toBeString()->toMatch('/^\d{6}$/');

    actingAs($inhaberin)->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('neuePin', null)->whereNot('offenePin', null));

    // Gespeichert ist nur der Hash.
    $zeile = SupportPin::query()->sole();
    expect($zeile->pin_hash)->not->toBe($pin)
        ->and(password_verify((string) $pin, $zeile->pin_hash))->toBeTrue();
});

it('laesst niemanden ohne Freigaberecht eine PIN erzeugen', function (): void {
    [$praxis] = pinAufbau();
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    $verwaltung = User::factory()->fuer($praxis, Role::Admin)->create();

    actingAs($empfang)->post(route('support-pin.store'))->assertForbidden();
    actingAs($verwaltung)->post(route('support-pin.store'))->assertForbidden();

    expect(SupportPin::query()->count())->toBe(0)
        ->and(fn () => app(Supportfreigabe::class)->erzeuge($empfang))->toThrow(RuntimeException::class);
});

it('laesst den Betreiber in der Impersonation keine PIN erzeugen, auch nicht mit Vollzugriff', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    // Der Kern des Pakets: waehrend der Impersonation spielt der Betreiber
    // die Rolle einer Inhaberin. Die PIN haengt deshalb an der Faehigkeit
    // ApproveImpersonation, die ihm ausdruecklich fehlt (WP-05).
    $sitzung = app(Impersonation::class)->approve($sitzung, $inhaberin);

    actingAs($betreiber)
        ->withSession(impersonationSitzung($sitzung))
        ->post(route('support-pin.store'))
        ->assertForbidden();

    app(ImpersonationContext::class)->set($sitzung);

    expect(fn () => app(Supportfreigabe::class)->erzeuge($betreiber))->toThrow(RuntimeException::class)
        ->and(SupportPin::query()->count())->toBe(0);
});

it('macht mit einer neuen PIN die vorige ungueltig', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $erste = app(Supportfreigabe::class)->erzeuge($inhaberin);
    $zweite = app(Supportfreigabe::class)->erzeuge($inhaberin);

    expect(pinAblehnung($sitzung, $betreiber, $erste))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and(pinAblehnung($sitzung, $betreiber, $zweite))->toBeNull()
        ->and($sitzung->refresh()->hatVollzugriff())->toBeTrue();
});

it('laesst eine abgelaufene, noch offene PIN keine neue verhindern', function (): void {
    [, $inhaberin] = pinAufbau();

    app(Supportfreigabe::class)->erzeuge($inhaberin);
    travel(16)->minutes();

    // Der Guard kennt die Uhr nicht -- ohne das Widerrufen in derselben
    // Transaktion stiesse die zweite PIN auf die erste.
    app(Supportfreigabe::class)->erzeuge($inhaberin);

    expect(SupportPin::query()->offen()->count())->toBe(1)
        ->and(SupportPin::query()->count())->toBe(2);
});

/* Einloesen ----------------------------------------------------------------- */

it('gibt mit der richtigen PIN Vollzugriff im Namen der Inhaberin', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    actingAs($betreiber)
        ->withSession(impersonationSitzung($sitzung))
        ->post(route('impersonation.pin', ['session' => $sitzung->uuid]), ['pin' => $pin])
        ->assertSessionHasNoErrors();

    $sitzung->refresh();

    expect($sitzung->mode)->toBe(ImpersonationMode::Full)
        ->and($sitzung->hatVollzugriff())->toBeTrue()
        ->and($sitzung->approved_by_user_id)->toBe($inhaberin->getKey())
        ->and($sitzung->approval_method)->toBe(Freigabeweg::Pin);

    $zeile = SupportPin::query()->sole();
    expect($zeile->used_at)->not->toBeNull()
        ->and($zeile->used_by_user_id)->toBe($betreiber->getKey())
        ->and($zeile->impersonation_session_id)->toBe($sitzung->getKey());

    $freigabe = AuditLog::query()->withoutGlobalScopes()
        ->where('event', AuditEvent::ImpersonationApproved->value)
        ->sole();

    expect($freigabe->context['via'] ?? null)->toBe('pin');
});

it('startet mit PIN aus dem Mandantenblatt gleich mit Vollzugriff', function (): void {
    $praxis = alsMandant();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    $betreiber = User::factory()->superAdmin()->create();
    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    actingAs($betreiber)
        ->post(route('impersonation.store'), [
            'organization' => $praxis->uuid,
            'reason' => 'Rueckruf der Praxis, Ticket 4712',
            'pin' => $pin,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    expect(ImpersonationSession::query()->sole()->hatVollzugriff())->toBeTrue();
});

it('laesst die Sitzung bei falscher PIN aus dem Mandantenblatt maskiert laufen', function (): void {
    $praxis = alsMandant();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    $betreiber = User::factory()->superAdmin()->create();
    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    actingAs($betreiber)
        ->post(route('impersonation.store'), [
            'organization' => $praxis->uuid,
            'reason' => 'Rueckruf der Praxis, Ticket 4712',
            'pin' => falschePin($pin),
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('pin');

    $sitzung = ImpersonationSession::query()->sole();

    expect($sitzung->laeuft())->toBeTrue()
        ->and($sitzung->hatVollzugriff())->toBeFalse();
});

it('laesst die PIN einer Praxis nicht in einer anderen wirken', function (): void {
    [, $inhaberinA] = pinAufbau('Praxis A');
    [, , $betreiberB, $sitzungB] = pinAufbau('Praxis B');

    $pinA = app(Supportfreigabe::class)->erzeuge($inhaberinA);

    expect(pinAblehnung($sitzungB, $betreiberB, $pinA))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($sitzungB->refresh()->hatVollzugriff())->toBeFalse();
});

it('laesst eine abgelaufene PIN nicht wirken', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    travel(16)->minutes();

    expect(pinAblehnung($sitzung, $betreiber, $pin))->toBeInstanceOf(SupportPinAbgelehnt::class);
});

it('laesst eine verbrauchte PIN nicht noch einmal wirken', function (): void {
    [$praxis, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    // Eine zweite Sitzung desselben Betreibers bekommt mit derselben PIN
    // keinen Vollzugriff.
    alsMandant($praxis);
    $zweite = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, noch einmal');

    expect(pinAblehnung($zweite, $betreiber, $pin))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($zweite->refresh()->hatVollzugriff())->toBeFalse();
});

it('laesst eine widerrufene PIN nicht wirken', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    actingAs($inhaberin)->delete(route('support-pin.destroy'))->assertRedirect();

    expect(pinAblehnung($sitzung, $betreiber, $pin))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and(SupportPin::query()->offen()->count())->toBe(0);
});

it('antwortet auf keine offene und auf eine falsche PIN gleich', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $ohne = pinAblehnung($sitzung, $betreiber, '123456');

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    $falsch = pinAblehnung($sitzung, $betreiber, falschePin($pin));

    // Sonst liesse sich abfragen, ob eine Praxis gerade eine PIN offen hat.
    expect($ohne)->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($falsch)->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($ohne?->getMessage())->toBe($falsch?->getMessage());
});

it('verbrennt die PIN beim fuenften Fehlversuch', function (): void {
    [$praxis, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    foreach (range(1, 5) as $versuch) {
        expect(pinAblehnung($sitzung, $betreiber, falschePin($pin)))->toBeInstanceOf(SupportPinAbgelehnt::class);
    }

    // Der Zaehler ist festgeschrieben, obwohl jeder Versuch mit einer
    // Ausnahme endete.
    expect(SupportPin::query()->sole()->failed_attempts)->toBe(5)
        ->and(pinAblehnung($sitzung, $betreiber, $pin))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($sitzung->refresh()->hatVollzugriff())->toBeFalse()
        ->and(praxisereignisse($praxis))->toContain(AuditEvent::SupportPinBurned->value);
});

it('drosselt einen Betreiber, der ueber mehrere Praxen hinweg raet', function (): void {
    config()->set('mrs.support_pin.versuche_je_betreiber_stunde', 3);

    $betreiber = User::factory()->superAdmin()->create();
    $ziele = [];

    foreach (['Praxis A', 'Praxis B', 'Praxis C', 'Praxis D'] as $name) {
        $praxis = alsMandant(organisation($name));
        $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
        $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
        $ziele[] = [$praxis, $pin];
    }

    // Je Praxis nur ein Versuch -- die Drosselung je PIN greift nie.
    foreach (array_slice($ziele, 0, 3) as [$praxis, $pin]) {
        alsMandant($praxis);
        $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');
        expect(pinAblehnung($sitzung, $betreiber, falschePin($pin)))->toBeInstanceOf(SupportPinAbgelehnt::class);
    }

    [$letzte, $richtige] = $ziele[3];
    alsMandant($letzte);
    $sitzung = app(Impersonation::class)->start($betreiber, $letzte, 'Ticket 4711, Termin fehlt');

    expect(pinAblehnung($sitzung, $betreiber, $richtige))->toBeInstanceOf(SupportPinAbgelehnt::class)
        ->and($sitzung->refresh()->hatVollzugriff())->toBeFalse();

    // Der Versuch unter der Drosselung hat die PIN nicht angefasst.
    alsMandant($letzte);
    expect(SupportPin::query()->sole()->failed_attempts)->toBe(0);
});

it('laesst die Rolle Finanzen keine PIN einloesen', function (): void {
    [, $inhaberin, , $sitzung] = pinAufbau();
    $finanzen = User::factory()->finanzen()->create();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);

    actingAs($finanzen)
        ->post(route('impersonation.pin', ['session' => $sitzung->uuid]), ['pin' => $pin])
        ->assertForbidden();

    expect(fn () => app(Supportfreigabe::class)->loese($sitzung, $finanzen, $pin))->toThrow(RuntimeException::class)
        ->and($sitzung->refresh()->hatVollzugriff())->toBeFalse()
        ->and(SupportPin::query()->offen()->count())->toBe(1);
});

it('beendet den Vollzugriff nach der Frist, auch ohne Aufraeumjob', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    $sitzung = app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    expect($sitzung->hatVollzugriff())->toBeTrue();

    travel((int) config('mrs.impersonation.full_ttl_minutes') + 1)->minutes();

    expect($sitzung->refresh()->hatVollzugriff())->toBeFalse();
});

/* Sichtbarkeit -------------------------------------------------------------- */

it('zeigt allen Benutzern der Praxis, solange der Support Vollzugriff hat', function (): void {
    [$praxis, $inhaberin, $betreiber, $sitzung] = pinAufbau();
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    // Maskiert ist kein Vollzugriff -- kein Hinweis.
    actingAs($empfang)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('supportzugriff', null));

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    actingAs($empfang)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('supportzugriff.uuid', $sitzung->uuid)
            ->has('supportzugriff.bis')
        );

    actingAs($inhaberin)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('supportzugriff.uuid', $sitzung->uuid));
});

it('laesst die Inhaberin den Zugriff beenden', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    actingAs($inhaberin)
        ->post(route('impersonation.beenden', ['session' => $sitzung->uuid]))
        ->assertRedirect();

    expect($sitzung->refresh()->ended_at)->not->toBeNull()
        ->and($sitzung->ended_reason)->toBe('ended_by_tenant');

    // Die naechste Anfrage des Betreibers laeuft ohne Impersonation.
    actingAs($betreiber)
        ->withSession(impersonationSitzung($sitzung))
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('impersonation', null));

    actingAs($inhaberin)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('supportzugriff', null));
});

it('laesst nur die Inhaberin den Zugriff beenden', function (): void {
    [$praxis, $inhaberin, $betreiber, $sitzung] = pinAufbau();
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    actingAs($empfang)
        ->post(route('impersonation.beenden', ['session' => $sitzung->uuid]))
        ->assertForbidden();

    expect($sitzung->refresh()->laeuft())->toBeTrue();
});

it('schreibt jeden Schritt ins Protokoll der Praxis', function (): void {
    [$praxis, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $erste = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->widerrufe($inhaberin);

    $zweite = app(Supportfreigabe::class)->erzeuge($inhaberin);
    foreach (range(1, 5) as $versuch) {
        pinAblehnung($sitzung, $betreiber, falschePin($zweite));
    }

    $dritte = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $dritte);

    actingAs($inhaberin)->post(route('impersonation.beenden', ['session' => $sitzung->uuid]));

    expect(praxisereignisse($praxis))->toContain(
        AuditEvent::SupportPinCreated->value,
        AuditEvent::SupportPinRevoked->value,
        AuditEvent::SupportPinFailed->value,
        AuditEvent::SupportPinBurned->value,
        AuditEvent::SupportPinRedeemed->value,
        AuditEvent::ImpersonationApproved->value,
        AuditEvent::ImpersonationEndedByTenant->value,
    );

    expect($erste)->not->toBe('');
});

it('laesst die Klartext-PIN in keinem Eintrag, keiner Logzeile und keiner Antwort', function (): void {
    $zeilen = [];
    Event::listen(MessageLogged::class, function (MessageLogged $zeile) use (&$zeilen): void {
        $zeilen[] = $zeile->message.' '.json_encode($zeile->context);
    });

    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $antworten = [];
    $antworten[] = actingAs($inhaberin)->post(route('support-pin.store'))->getContent();

    // Die eine Antwort an die Inhaberin, die sie tragen darf -- und muss.
    $pin = (string) actingAs($inhaberin)->get(route('team.index'))->inertiaProps('neuePin.pin');
    expect($pin)->toMatch('/^\d{6}$/');

    $antworten[] = actingAs($inhaberin)->get(route('team.index'))->getContent();

    $mitSitzung = fn () => actingAs($betreiber)->withSession(impersonationSitzung($sitzung));

    // Fehlversuche mit der eingetippten PIN davor und dahinter.
    $antworten[] = $mitSitzung()->post(route('impersonation.pin', ['session' => $sitzung->uuid]), ['pin' => falschePin($pin)])->getContent();
    $antworten[] = $mitSitzung()->get(route('dashboard'))->getContent();
    $antworten[] = $mitSitzung()->post(route('impersonation.pin', ['session' => $sitzung->uuid]), ['pin' => $pin])->getContent();
    $antworten[] = $mitSitzung()->get(route('dashboard'))->getContent();
    $antworten[] = $mitSitzung()->get(route('audit.index'))->getContent();
    $antworten[] = actingAs($inhaberin)->get(route('audit.index'))->getContent();

    $protokoll = AuditLog::query()->withoutGlobalScopes()->get()
        ->map(fn (AuditLog $eintrag): string => json_encode([$eintrag->context, $eintrag->reason, $eintrag->changed_fields]) ?: '')
        ->implode(' ');

    $alles = $protokoll.' '.implode(' ', $zeilen).' '.implode(' ', $antworten);

    expect($alles)->not->toContain($pin)
        ->and($alles)->not->toContain(falschePin($pin));
});

/* Bestand ------------------------------------------------------------------- */

it('laesst die Klick-Freigabe unveraendert und vermerkt den Weg', function (): void {
    [, $inhaberin, , $sitzung] = pinAufbau();

    actingAs($inhaberin)
        ->post(route('impersonation.approve', ['session' => $sitzung->uuid]))
        ->assertRedirect();

    $sitzung->refresh();

    expect($sitzung->hatVollzugriff())->toBeTrue()
        ->and($sitzung->approval_method)->toBe(Freigabeweg::Klick)
        ->and(SupportPin::query()->count())->toBe(0);
});

it('zeigt der Inhaberin die Support-Sitzungen mit Freigabeweg', function (): void {
    [, $inhaberin, $betreiber, $sitzung] = pinAufbau();

    $pin = app(Supportfreigabe::class)->erzeuge($inhaberin);
    app(Supportfreigabe::class)->loese($sitzung, $betreiber, $pin);

    actingAs($inhaberin)->get(route('team.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('supportSitzungen', 1)
            ->where('supportSitzungen.0.uuid', $sitzung->uuid)
            ->where('supportSitzungen.0.betreiber', $betreiber->name)
            ->where('supportSitzungen.0.freigabeweg', Freigabeweg::Pin->label())
            ->where('supportSitzungen.0.laeuft', true)
        );

    expect(SupportPinController::NEUE_PIN)->toBeString();
});

it('loescht alte PINs mit der Aufbewahrung', function (): void {
    [, $inhaberin] = pinAufbau();

    app(Supportfreigabe::class)->erzeuge($inhaberin);
    travel((int) config('mrs.support_pin.aufbewahrung_tage') + 1)->days();
    app(Supportfreigabe::class)->erzeuge($inhaberin);

    ohneMandant();
    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true]))->toBe(0);

    alsMandant(Organization::query()->sole());
    expect(SupportPin::query()->count())->toBe(1);
});
