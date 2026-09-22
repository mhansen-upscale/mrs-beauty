<?php

declare(strict_types=1);

use App\Enums\CancellationReason;
use App\Enums\NotificationKind;
use App\Models\Appointment;
use App\Notifications\Terminnachricht;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-14, Abnahmekriterien 33 und 34 -- die Kalenderdatei
|--------------------------------------------------------------------------
|
| Aus WP-13 hierher verschoben: dort war die Kalenderlogik noch nicht da.
|
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

function terminFuer(Szenario $szenario): Appointment
{
    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    return $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);
}

function nachricht(Appointment $termin, NotificationKind $art): MailMessage
{
    return (new Terminnachricht($termin, $art, 'Praxis Nordlicht'))->toMail(new AnonymousNotifiable);
}

it('haengt an Bestaetigung, Verschiebung und Absage eine Kalenderdatei', function (): void {
    $termin = terminFuer(new Szenario);

    foreach ([NotificationKind::Confirmation, NotificationKind::Rescheduled, NotificationKind::Cancellation] as $art) {
        $anhaenge = nachricht($termin, $art)->rawAttachments;

        expect($anhaenge)->toHaveCount(1)
            ->and($anhaenge[0]['name'])->toBe('termin.ics');
    }
});

it('haengt an Anfrage und Erinnerung keine an', function (): void {
    // Ein angefragter Termin gehoert noch nicht in einen Kalender, und wer
    // eine Erinnerung bekommt, hat den Eintrag laengst.
    $termin = terminFuer(new Szenario);

    expect(nachricht($termin, NotificationKind::RequestReceived)->rawAttachments)->toBeEmpty()
        ->and(nachricht($termin, NotificationKind::Reminder)->rawAttachments)->toBeEmpty();
});

it('nennt im Titel der Kalenderdatei keine Behandlung', function (): void {
    $szenario = new Szenario;
    $termin = terminFuer($szenario);

    $ics = (string) nachricht($termin, NotificationKind::Confirmation)->rawAttachments[0]['data'];

    $titel = collect(explode("\r\n", $ics))->first(fn (string $zeile): bool => str_starts_with($zeile, 'SUMMARY:'));

    expect($titel)->toBe('SUMMARY:Termin bei Praxis Nordlicht')
        ->and($titel)->not->toContain($szenario->aufbau->art->name)
        ->and($titel)->not->toContain($szenario->kontakt->last_name)
        // Im Text darf sie stehen -- dorthin sieht nur, wer den Eintrag oeffnet.
        ->and($ics)->toContain('DESCRIPTION:'.$szenario->aufbau->art->name);
});

it('traegt die angezeigte Zeit ein, nicht die belegte', function (): void {
    $szenario = new Szenario(dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $termin = terminFuer($szenario);

    $ics = (string) nachricht($termin, NotificationKind::Confirmation)->rawAttachments[0]['data'];

    expect($ics)->toContain('DTSTART:'.$termin->starts_at->utc()->format('Ymd\THis\Z'))
        ->and($ics)->toContain('DTEND:'.$termin->ends_at->utc()->format('Ymd\THis\Z'))
        ->and($ics)->not->toContain('DTSTART:'.$termin->blocked_from->utc()->format('Ymd\THis\Z'));
});

it('behaelt ueber alle Nachrichten dieselbe Kennung', function (): void {
    // Nur so ersetzt die Verschiebung den Eintrag, statt einen zweiten
    // anzulegen -- und nur so entfernt die Absage ihn wieder.
    $szenario = new Szenario;
    $termin = terminFuer($szenario);

    $kennung = fn (NotificationKind $art): string => (string) collect(
        explode("\r\n", (string) nachricht($termin, $art)->rawAttachments[0]['data'])
    )->first(fn (string $zeile): bool => str_starts_with($zeile, 'UID:'));

    expect($kennung(NotificationKind::Confirmation))->toBe($kennung(NotificationKind::Cancellation))
        ->and($kennung(NotificationKind::Confirmation))->toContain((string) $termin->uuid);
});

it('meldet die Absage als Absage', function (): void {
    $szenario = new Szenario;
    $termin = terminFuer($szenario);

    app(Terminplaner::class)->sageAb($termin, CancellationReason::Practice, jetzt: $szenario->jetzt());

    $post = nachricht($termin->fresh()?->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']) ?? $termin, NotificationKind::Cancellation);
    $ics = (string) $post->rawAttachments[0]['data'];

    expect($ics)->toContain('METHOD:CANCEL')
        ->toContain('STATUS:CANCELLED')
        ->and($post->rawAttachments[0]['options']['mime'])->toContain('method=CANCEL');
});

it('bricht zu lange Zeilen nach RFC 5545 um', function (): void {
    $szenario = new Szenario;
    $szenario->aufbau->standort->update([
        'name' => 'Praxisgemeinschaft am Alsterufer für ästhetische und plastische Chirurgie',
        'street' => 'Rothenbaumchaussee 128b, Aufgang C, dritter Stock',
    ]);

    $termin = terminFuer($szenario);
    $ics = (string) nachricht($termin, NotificationKind::Confirmation)->rawAttachments[0]['data'];

    foreach (explode("\r\n", $ics) as $zeile) {
        expect(strlen($zeile))->toBeLessThanOrEqual(75);
    }

    // Und die Anschrift steht trotzdem vollstaendig darin.
    expect(str_replace("\r\n ", '', $ics))->toContain('Aufgang C');
});
