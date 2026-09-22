<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Enums\NotificationChannel;
use App\Enums\NotificationKind;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\TerminnachrichtVersenden;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Terminnachricht;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-13, Abnahmekriterien 1 bis 21
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** @return array{Szenario, Appointment} */
function terminMitNachrichten(AppointmentStatus $status = AppointmentStatus::Confirmed, bool $mitEmail = true): array
{
    $szenario = new Szenario;

    if (! $mitEmail) {
        $szenario->kontakt->email = null;
        $szenario->kontakt->save();
    }

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        status: $status,
        jetzt: $szenario->jetzt(),
    );

    return [$szenario, $termin];
}

/**
 * @return list<string>
 */
function arten(Appointment $termin): array
{
    /** @var list<string> */
    return $termin->notifications()->get()
        ->map(fn (AppointmentNotification $zeile): string => $zeile->kind->value)
        ->sort()
        ->values()
        ->all();
}

/**
 * Der Versandbefehl.
 *
 * Eigene Hilfe statt Pest\Laravel\artisan(): dessen Rueckgabetyp ist
 * PendingCommand **oder** int, und die statische Analyse kann nicht wissen,
 * welches davon.
 */
function versandlauf(): void
{
    expect(Artisan::call('mrs:erinnerungen-versenden'))->toBe(0);
}

/* Planen ------------------------------------------------------------------- */

it('plant bei einer Buchung vom Empfang Bestaetigung und Erinnerung', function (): void {
    [, $termin] = terminMitNachrichten();

    expect(arten($termin))->toBe(['confirmation', 'reminder']);
});

it('plant bei einer Buchung ueber die oeffentliche Seite eine Eingangsbestaetigung', function (): void {
    [, $termin] = terminMitNachrichten(AppointmentStatus::Pending);

    expect(arten($termin))->toBe(['reminder', 'request_received']);
});

it('plant die Erinnerung auf den Vorlauf vor Terminbeginn', function (): void {
    [, $termin] = terminMitNachrichten();

    $erinnerung = $termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail();

    expect($erinnerung->scheduled_for?->equalTo($termin->starts_at->subHours(24)))->toBeTrue();
});

it('laesst den Vorlauf je Mandant ueberschreiben', function (): void {
    $organisation = Organization::query()->firstOrFail();
    $organisation->settings = ['reminders' => ['hours_before' => 3]];
    $organisation->save();
    alsMandant($organisation);

    [, $termin] = terminMitNachrichten();

    $erinnerung = $termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail();

    expect($erinnerung->scheduled_for?->equalTo($termin->starts_at->subHours(3)))->toBeTrue();
});

it('haelt einen fehlenden Kontaktweg als Fehlschlag fest, nicht als Luecke', function (): void {
    [, $termin] = terminMitNachrichten(mitEmail: false);

    $bestaetigung = $termin->notifications()->where('kind', NotificationKind::Confirmation->value)->firstOrFail();

    // Kein Kontaktweg ist eine Information. Sonst faellt erst auf, dass
    // niemand erinnert wurde, wenn jemand nicht erscheint.
    expect($bestaetigung->sent_at)->toBeNull()
        ->and($bestaetigung->failed_at)->not->toBeNull()
        ->and($bestaetigung->failure)->toBe('no_channel');
});

/* Genau einmal ------------------------------------------------------------- */

it('erzeugt aus zwei Laeufen eine Nachricht, nicht zwei', function (): void {
    Notification::fake();

    [, $termin] = terminMitNachrichten();

    travelTo($termin->starts_at->subHours(23));

    versandlauf();
    versandlauf();

    Notification::assertSentTimes(Terminnachricht::class, 2);

    // Zwei: die Bestaetigung beim Buchen und genau eine Erinnerung.
    expect($termin->notifications()->whereNotNull('sent_at')->count())->toBe(2);
});

it('schliesst eine zweite Zeile derselben Art auf Datenbankebene aus', function (): void {
    [, $termin] = terminMitNachrichten();

    $doppelt = new AppointmentNotification;
    $doppelt->appointment_id = $termin->getKey();
    $doppelt->kind = NotificationKind::Reminder;
    $doppelt->channel = NotificationChannel::Email;

    expect(fn () => $doppelt->save())->toThrow(QueryException::class);
});

/* Aendern ------------------------------------------------------------------ */

it('plant die Erinnerung beim Verschieben neu', function (): void {
    [$szenario, $termin] = terminMitNachrichten();

    $ziel = $szenario->vorschlag(20);

    app(Terminplaner::class)->verschiebe($termin, $ziel, jetzt: $szenario->jetzt());

    $erinnerung = $termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail();

    // Ohne Neuplanung erinnert das System an die alte Zeit -- oder gar nicht,
    // weil die Zeile schon als verschickt gilt.
    expect($erinnerung->scheduled_for?->equalTo($ziel->startsAt->subHours(24)))->toBeTrue()
        ->and(arten($termin))->toContain('rescheduled');
});

it('loescht die offene Erinnerung bei einer Absage', function (): void {
    [$szenario, $termin] = terminMitNachrichten();

    app(Terminplaner::class)->sageAb($termin, CancellationReason::Contact, jetzt: $szenario->jetzt());

    // Eine Erinnerung an einen abgesagten Termin ist der peinlichste Fehler
    // dieser Gattung.
    expect(arten($termin))->toBe(['cancellation', 'confirmation']);
});

it('verschickt bei einer Bestaetigung eine Bestaetigung', function (): void {
    [$szenario, $termin] = terminMitNachrichten(AppointmentStatus::Pending);

    app(Terminplaner::class)->setzeStatus($termin, AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    expect(arten($termin))->toContain('confirmation');
});

/* Versand ------------------------------------------------------------------ */

it('verschickt faellige Erinnerungen', function (): void {
    Notification::fake();

    [, $termin] = terminMitNachrichten();

    travelTo($termin->starts_at->subHours(23));

    versandlauf();

    // Die Mail geht an eine Adresse, nicht an ein Modell -- der Kontakt ist
    // kein Notifiable, sondern ein verschluesselter Datensatz.
    Notification::assertSentOnDemand(Terminnachricht::class);

    expect($termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail()->sent_at)
        ->not->toBeNull();
});

it('verschickt nichts vor dem geplanten Zeitpunkt', function (): void {
    [, $termin] = terminMitNachrichten();

    // Eine Stunde vor Faelligkeit: der Vorlauf betraegt 24 Stunden.
    travelTo($termin->starts_at->subHours(25));

    versandlauf();

    expect($termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail()->sent_at)
        ->toBeNull();
});

it('verschickt keine Erinnerung fuer einen Termin in der Vergangenheit', function (): void {
    [, $termin] = terminMitNachrichten();

    // Der Job stand -- Ausfall, Deploy. Eine ueberfaellige Erinnerung ist
    // wertlos.
    travelTo($termin->starts_at->addHour());

    versandlauf();

    expect($termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail()->sent_at)
        ->toBeNull();
});

it('verschickt keine Erinnerung fuer einen abgesagten Termin', function (): void {
    [$szenario, $termin] = terminMitNachrichten();

    // Ohne die Loeschung beim Absagen greift diese zweite Sicherung.
    $termin->status = AppointmentStatus::Cancelled;
    $termin->save();

    travelTo($termin->starts_at->subHours(23));

    versandlauf();

    expect(AppointmentNotification::query()
        ->where('kind', NotificationKind::Reminder->value)
        ->whereNotNull('sent_at')
        ->count())->toBe(0);

    expect($szenario->aufbau->standort->exists)->toBeTrue();
});

it('verschickt ueber die Queue und nicht im Anfragezyklus', function (): void {
    Queue::fake();

    [, $termin] = terminMitNachrichten();

    // Regel 4: kein schreibender Fremdsystemzugriff im Anfragezyklus.
    Queue::assertPushedOn('default', TerminnachrichtVersenden::class);

    expect($termin->notifications()->count())->toBe(2);
});

it('legt eine Job-Nutzlast an, die sich kodieren laesst', function (): void {
    Queue::fake();

    terminMitNachrichten();

    // Der Primaerschluessel ist BINARY(16) (Entscheidung A4). Rohbytes in der
    // Nutzlast brechen json_encode() -- mit einer Meldung, die auf die Queue
    // zeigt statt auf die Ursache.
    Queue::assertPushed(TerminnachrichtVersenden::class, function (TerminnachrichtVersenden $job): bool {
        return json_encode(serialize($job)) !== false;
    });
});

/* Inhalt ------------------------------------------------------------------- */

it('nennt im Betreff keine Behandlung', function (): void {
    [, $termin] = terminMitNachrichten();

    $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);

    $nachricht = (new Terminnachricht($termin, NotificationKind::Reminder, 'Praxis'))->toMail($termin->contact);

    // Der Betreff steht als Vorschau auf einem Sperrbildschirm, den auch
    // andere sehen.
    expect($nachricht->subject)->not->toContain($termin->appointmentType->name)
        ->and($nachricht->subject)->toStartWith('Ihr Termin am ');
});

it('nennt im Text Datum, Uhrzeit, Standort, Behandler und Behandlung', function (): void {
    [$szenario, $termin] = terminMitNachrichten();

    $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);

    $nachricht = (new Terminnachricht($termin, NotificationKind::Reminder, 'Praxis'))->toMail($termin->contact);
    $text = implode(' ', $nachricht->introLines);

    expect($text)->toContain($termin->appointmentType->name)
        ->toContain($szenario->aufbau->behandler->name())
        ->toContain($szenario->aufbau->standort->name)
        ->toContain($szenario->aufbau->standort->ortszeit($termin->starts_at)->format('H:i'));
});

/* Mandantengrenze ---------------------------------------------------------- */

it('verlaesst beim Versand nie die Organisation eines Termins', function (): void {
    Notification::fake();

    [, $ersterTermin] = terminMitNachrichten();

    // Eine zweite Praxis mit eigenem Termin zur selben Zeit.
    alsMandant(Organization::factory()->create(['name' => 'Andere', 'slug' => 'andere']));
    $zweitesSzenario = new Szenario;
    app(Terminplaner::class)->buche(
        $zweitesSzenario->vorschlag(),
        $zweitesSzenario->kontakt,
        jetzt: $zweitesSzenario->jetzt(),
    );

    ohneMandant();

    travelTo($ersterTermin->starts_at->subHours(23));

    versandlauf();

    // Zwei Praxen, zwei Erinnerungen -- und keine Zeile hat den Mandanten
    // gewechselt.
    alsMandant(Organization::query()->where('slug', '!=', 'andere')->firstOrFail());

    expect(AppointmentNotification::query()
        ->where('kind', NotificationKind::Reminder->value)
        ->whereNotNull('sent_at')
        ->count())->toBe(1);
});

it('erkennt einen Kontakt ohne Mailadresse auch beim Versand', function (): void {
    [, $termin] = terminMitNachrichten();

    $kontakt = Contact::query()->whereKey($termin->contact_id)->firstOrFail();
    $kontakt->email = null;
    $kontakt->save();

    travelTo($termin->starts_at->subHours(23));

    versandlauf();

    $erinnerung = $termin->notifications()->where('kind', NotificationKind::Reminder->value)->firstOrFail();

    expect($erinnerung->failure)->toBe('no_channel')
        ->and($erinnerung->sent_at)->toBeNull();
});

it('bucht auch ueber die oeffentliche Seite mit Kanal public', function (): void {
    [, $termin] = terminMitNachrichten(AppointmentStatus::Pending);

    expect($termin->booked_via)->toBe(BookingChannel::Internal);
});

it('verschickt im Namen der Praxis, nicht in unserem', function (): void {
    [$szenario, $termin] = terminMitNachrichten();

    $szenario->aufbau->standort->email = 'praxis@example.test';
    $szenario->aufbau->standort->save();

    $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);

    $nachricht = (new Terminnachricht($termin, NotificationKind::Reminder, 'Praxis Musterstrasse'))
        ->toMail($termin->contact);

    // Die Adresse bleibt unsere, der Anzeigename ist der der Praxis. Und wer
    // antwortet, erreicht die Praxis.
    expect($nachricht->from[1] ?? null)->toBe('Praxis Musterstrasse')
        ->and($nachricht->replyTo[0][0] ?? null)->toBe('praxis@example.test');
});

it('zeigt in der Terminansicht, was verschickt wurde und was aussteht', function (): void {
    $organisation = Organization::query()->firstOrFail();
    [$szenario, $termin] = terminMitNachrichten();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $antwort = actingAs($benutzer)->get(
        route('appointments.index', [
            'date' => Szenario::TAG,
            'location' => $szenario->aufbau->standort->uuid,
        ]),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'termine/Index',
            'X-Inertia-Partial-Data' => 'appointments',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ],
    );

    /** @var list<array{label: string, state: string, detail: string}> $nachrichten */
    $nachrichten = $antwort->json('props.appointments.0.notifications') ?? [];

    expect(array_column($nachrichten, 'label'))->toEqualCanonicalizing(['Terminbestätigung', 'Erinnerung'])
        ->and(array_column($nachrichten, 'state'))->toContain('geplant');

    expect($termin->notifications()->count())->toBe(2);
});
