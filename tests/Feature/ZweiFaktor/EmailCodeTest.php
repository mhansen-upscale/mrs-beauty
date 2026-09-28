<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Notifications\Anmeldecode;
use App\ZweiFaktor\EmailCode;
use App\ZweiFaktor\Zweck;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 29 bis 33 -- der Code per E-Mail
|--------------------------------------------------------------------------
|
| Sechs Stellen sind schwach, und das ist in Ordnung, solange der Code kurz
| lebt, einmal wirkt und nirgends im Klartext liegen bleibt.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Notification::fake();
});

it('schickt nach dem Passwort genau eine Mail mit sechsstelligem Code, nicht im Betreff', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertRedirect(route('login.zwei-faktor'));

    Notification::assertSentToTimes($person, Anmeldecode::class, 1);

    $code = Zugang::codeAusMail($person);
    $mail = Notification::sent($person, Anmeldecode::class)->sole()->toMail($person);

    expect($code)->toMatch('/^\d{6}$/')
        ->and((string) $mail->subject)->not->toContain($code);

    post(route('login.zwei-faktor.pruefen'), ['code' => $code])->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($person);
});

it('laesst einen Code nur einmal und nur kurz gelten, und ein neuer entwertet den alten', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());
    $codes = app(EmailCode::class);

    $codes->sende($person, Zweck::Anmeldung);
    $erster = Zugang::codeAusMail($person);

    expect($codes->pruefe($person, Zweck::Anmeldung, $erster))->toBeTrue()
        ->and($codes->pruefe($person, Zweck::Anmeldung, $erster))->toBeFalse();

    // Zwischen zwei Mails liegt der Abstand, den auch "Erneut senden" verlangt.
    $abstand = (int) config('mrs.zwei_faktor.email_erneut_nach_sekunden') + 1;

    travel($abstand)->seconds();
    $codes->sende($person, Zweck::Anmeldung);
    $zweiter = Zugang::codeAusMail($person);
    travel((int) config('mrs.zwei_faktor.email_code_gueltig_minuten') + 1)->minutes();

    expect($codes->pruefe($person, Zweck::Anmeldung, $zweiter))->toBeFalse();

    $codes->sende($person, Zweck::Anmeldung);
    $alt = Zugang::codeAusMail($person);
    travel($abstand)->seconds();
    $codes->sende($person, Zweck::Anmeldung);
    $neu = Zugang::codeAusMail($person);

    expect($alt === $neu || ! $codes->pruefe($person, Zweck::Anmeldung, $alt))->toBeTrue()
        ->and($codes->pruefe($person, Zweck::Anmeldung, $neu))->toBeTrue();
});

it('laesst erneut senden erst nach dem Abstand und nur so oft je Stunde', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);

    post(route('login.zwei-faktor.erneut'))->assertSessionHasErrors('code');
    Notification::assertSentToTimes($person, Anmeldecode::class, 1);

    $abstand = (int) config('mrs.zwei_faktor.email_erneut_nach_sekunden');
    $hoechstens = (int) config('mrs.zwei_faktor.email_sendungen_je_stunde');

    foreach (range(2, $hoechstens) as $_) {
        travel($abstand + 1)->seconds();
        post(route('login.zwei-faktor.erneut'))->assertSessionHasNoErrors();
    }

    Notification::assertSentToTimes($person, Anmeldecode::class, $hoechstens);

    travel($abstand + 1)->seconds();
    post(route('login.zwei-faktor.erneut'))->assertSessionHasErrors('code');

    Notification::assertSentToTimes($person, Anmeldecode::class, $hoechstens);
});

it('trennt den Code der Einrichtung von dem der Anmeldung', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());
    $codes = app(EmailCode::class);

    $codes->sende($person, Zweck::Einrichtung);
    $code = Zugang::codeAusMail($person);

    expect($codes->pruefe($person, Zweck::Anmeldung, $code))->toBeFalse()
        ->and($codes->pruefe($person, Zweck::Einrichtung, $code))->toBeTrue();
});

it('laesst den Code nirgends im Klartext liegen', function (): void {
    $zeilen = [];
    Event::listen(MessageLogged::class, function (MessageLogged $zeile) use (&$zeilen): void {
        $zeilen[] = $zeile->message.' '.json_encode($zeile->context);
    });

    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    $code = Zugang::codeAusMail($person);

    // Im Cache liegt ein Hash, nicht der Code.
    $eintrag = Cache::get('zwei-faktor:code:anmeldung:'.$person->uuid);

    expect($eintrag)->toBeArray()
        ->and($eintrag['hash'])->not->toBe($code)
        ->and(Hash::check($code, $eintrag['hash']))->toBeTrue();

    // Die ausstehende Anmeldung in der Sitzung kennt ihn nicht.
    expect(session('zwei_faktor_anmeldung'))->toBeArray()
        ->and(array_values(session('zwei_faktor_anmeldung')))->not->toContain($code);

    // In der Warteschlange nur verschluesselt -- dass dort wirklich kein
    // Klartext liegt, prueft tests/Feature/Benachrichtigung/WarteschlangeTest.
    expect(new Anmeldecode($code, 10, false))->toBeInstanceOf(ShouldQueue::class)
        ->and(new Anmeldecode($code, 10, false))->toBeInstanceOf(ShouldBeEncrypted::class);

    // Nicht in einem falschen Versuch, der ins Protokoll oder ins Log ginge.
    post(route('login.zwei-faktor.pruefen'), ['code' => '000000']);
    post(route('login.zwei-faktor.pruefen'), ['code' => $code]);

    $protokoll = AuditLog::query()->withoutGlobalScopes()->get()
        ->map(fn (AuditLog $eintrag): string => json_encode([$eintrag->context, $eintrag->reason, $eintrag->changed_fields]) ?: '')
        ->implode(' ');

    expect($protokoll)->not->toContain($code)
        ->and(implode(' ', $zeilen))->not->toContain($code);
});
