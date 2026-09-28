<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\ZweiFaktor\Wiederherstellungscodes;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 44 und 45 -- das Protokoll
|--------------------------------------------------------------------------
|
| Was mit dem zweiten Faktor einer Person geschieht, steht im Protokoll --
| bei Praxispersonen in dem der Praxis, bei Betreibern ohne Organisation.
| **Nie das Geheimnis, nie ein Code** (C5).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Notification::fake();
});

/**
 * @return list<string>
 */
function zweiFaktorEreignisse(?string $organisation): array
{
    return array_values(AuditLog::query()->withoutGlobalScopes()
        ->where('event', 'like', 'two_factor.%')
        ->where(fn ($abfrage) => $organisation === null ? $abfrage->whereNull('organization_id') : $abfrage->where('organization_id', $organisation))
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->pluck('event')
        ->map(fn (AuditEvent $ereignis): string => $ereignis->value)
        ->all());
}

it('protokolliert jeden Schritt am zweiten Faktor im Protokoll der Praxis', function (): void {
    $person = Zugang::inhaberin();

    actingAs($person)->post(route('zwei-faktor.app'), ['current_password' => 'password']);
    actingAs($person)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode((string) session('zwei_faktor_einrichtung.geheimnis'))]);
    actingAs($person)->post(route('zwei-faktor.codes'), ['current_password' => 'password']);
    $codes = session('zwei_faktor_codes');
    post(route('logout'));

    // Mit einem Wiederherstellungscode hinein ...
    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    post(route('login.zwei-faktor.pruefen'), ['code' => $codes[0]]);
    post(route('logout'));

    // ... eine Anmeldung nach fuenf falschen Codes verworfen ...
    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    foreach (range(1, (int) config('mrs.zwei_faktor.max_versuche')) as $_) {
        post(route('login.zwei-faktor.pruefen'), ['code' => '000000']);
    }

    // ... und abgeschaltet.
    actingAs($person->fresh() ?? $person)->delete(route('zwei-faktor.destroy'), ['current_password' => 'password']);

    expect(zweiFaktorEreignisse($person->organization_id))->toBe([
        AuditEvent::TwoFactorEnabled->value,
        AuditEvent::TwoFactorRecoveryCodesRenewed->value,
        AuditEvent::TwoFactorRecoveryCodeUsed->value,
        AuditEvent::TwoFactorChallengeLocked->value,
        AuditEvent::TwoFactorDisabled->value,
    ]);

    $eingeloest = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::TwoFactorRecoveryCodeUsed->value)->sole();

    expect($eingeloest->actor_user_id)->toBe($person->getKey())
        ->and($eingeloest->context)->toBe(['uebrig' => 7]);
});

it('protokolliert beim Betreiber ohne Organisation', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->post(route('zwei-faktor.app'), ['current_password' => 'password']);
    actingAs($betreiber)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode((string) session('zwei_faktor_einrichtung.geheimnis'))]);

    expect(zweiFaktorEreignisse(null))->toBe([AuditEvent::TwoFactorEnabled->value]);
});

it('laesst Geheimnis und Codes in keinem Eintrag, keiner Logzeile und keiner Antwort', function (): void {
    $zeilen = [];
    Event::listen(MessageLogged::class, function (MessageLogged $zeile) use (&$zeilen): void {
        $zeilen[] = $zeile->message.' '.json_encode($zeile->context);
    });

    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    $codes = app(Wiederherstellungscodes::class)->erzeuge($person);

    $antworten = [];
    $antworten[] = post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])->getContent();
    $antworten[] = post(route('login.zwei-faktor.pruefen'), ['code' => 'FALS-CHER-CODE-XXXX'])->getContent();
    $antworten[] = post(route('login.zwei-faktor.pruefen'), ['code' => $codes[0]])->getContent();
    $antworten[] = actingAs($person->fresh() ?? $person)->get(route('zwei-faktor.edit'))->getContent();
    $antworten[] = actingAs($person->fresh() ?? $person)->get(route('dashboard'))->getContent();

    $protokoll = AuditLog::query()->withoutGlobalScopes()->get()
        ->map(fn (AuditLog $eintrag): string => json_encode([$eintrag->context, $eintrag->reason, $eintrag->changed_fields]) ?: '')
        ->implode(' ');

    $alles = $protokoll.' '.implode(' ', $zeilen).' '.implode(' ', $antworten);

    expect($alles)->not->toContain(Zugang::GEHEIMNIS);

    foreach ($codes as $code) {
        expect($alles)->not->toContain($code)
            ->and($alles)->not->toContain(str_replace('-', '', $code));
    }
});
