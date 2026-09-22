<?php

declare(strict_types=1);

use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\Auskunft;
use App\Datenschutz\Betroffenenrechte;
use App\Datenschutz\Einwilligungen;
use App\Enums\AttachmentContext;
use App\Enums\ChannelType;
use App\Enums\ConsentType;
use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Kontakte\Zusammenfuehrung;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\Attachment;
use App\Models\ChannelIdentity;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\DataSubjectRequest;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Taggable;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-18 -- Betroffenenrechte (Artikel 15, 16, 17 DSGVO)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Storage::fake('local');
});

/**
 * Eine Person mit Daten in **jeder** Tabelle, die Personenbezug hat.
 *
 * @return array{Szenario, Contact, Appointment}
 */
function vollstaendigePerson(): array
{
    $szenario = new Szenario;
    $kontakt = $szenario->kontakt;

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $kontakt, jetzt: $szenario->jetzt());

    $identitaet = $kontakt->channelIdentities()->create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '+49 170 1234567',
    ]);

    app(Einwilligungen::class)->erteile($identitaet, ConsentType::WhatsApp, 'v1', 'Ja.');

    Note::create([
        'notable_type' => Contact::class,
        'notable_id' => $kontakt->getKey(),
        'body' => 'Kommt immer zu spät.',
    ]);

    Note::create([
        'notable_type' => Appointment::class,
        'notable_id' => $termin->getKey(),
        'body' => 'Termin telefonisch bestätigt.',
    ]);

    $schlagwort = Tag::create(['name' => 'Stammkundin']);
    Taggable::create([
        'tag_id' => $schlagwort->getKey(),
        'taggable_type' => Contact::class,
        'taggable_id' => $kontakt->getKey(),
    ]);

    app(Anhangspeicher::class)->lege($kontakt, 'foto', 'foto.jpg', AttachmentContext::Chat);
    app(Anhangspeicher::class)->lege($termin, 'bogen', 'einwilligung.pdf', AttachmentContext::Document);

    return [$szenario, $kontakt, $termin];
}

it('legt eine Person mit Daten in jeder Tabelle an', function (): void {
    // Die Gegenprobe zum Loeschtest: greift der Aufbau ins Leere, prueft der
    // Test darunter nichts.
    [, $kontakt] = vollstaendigePerson();

    expect(Appointment::query()->count())->toBe(1)
        ->and(Lead::query()->count())->toBe(1)
        ->and(ChannelIdentity::query()->count())->toBe(1)
        ->and(Consent::query()->count())->toBe(1)
        ->and(Note::query()->count())->toBe(2)
        ->and(Taggable::query()->count())->toBe(1)
        ->and(Attachment::query()->count())->toBe(2)
        ->and(AppointmentNotification::query()->count())->toBeGreaterThan(0)
        ->and(AppointmentSlot::query()->whereNotNull('appointment_id')->count())->toBeGreaterThan(0)
        ->and($kontakt->exists)->toBeTrue();
});

it('entfernt bei einer Loeschung jede Tabelle einzeln', function (): void {
    [, $kontakt] = vollstaendigePerson();

    app(Betroffenenrechte::class)->loeschung($kontakt);

    expect(Contact::query()->count())->toBe(0)
        ->and(Appointment::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(ChannelIdentity::query()->count())->toBe(0)
        ->and(Consent::query()->count())->toBe(0)
        ->and(Note::query()->count())->toBe(0)
        ->and(Taggable::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0)
        ->and(AppointmentNotification::query()->count())->toBe(0)
        // Die Zeit wird frei, die Slot-Zeilen bleiben: sie gehoeren dem
        // Behandler, nicht der Person.
        ->and(AppointmentSlot::query()->whereNotNull('appointment_id')->count())->toBe(0)
        ->and(AppointmentSlot::query()->count())->toBeGreaterThan(0);
});

it('nimmt bei einer Loeschung die Dateien mit', function (): void {
    // Sonst bleiben Fotos auf dem Speicher liegen, waehrend der Datensatz
    // verschwunden ist.
    [, $kontakt] = vollstaendigePerson();

    $pfade = Attachment::query()->pluck('path')->all();

    expect($pfade)->toHaveCount(2);

    app(Betroffenenrechte::class)->loeschung($kontakt);

    foreach ($pfade as $pfad) {
        expect(Storage::disk('local')->exists((string) $pfad))->toBeFalse();
    }
});

it('raeumt den Sicherungsstand einer Zusammenfuehrung mit ab', function (): void {
    // Der Snapshot enthaelt die Felder des Verlierers. Wer den Kontakt
    // loescht und ihn stehen laesst, hat ihn nicht geloescht, nur versteckt.
    $gewinner = Contact::create(['first_name' => 'Anna', 'last_name' => 'Bleibt']);
    $verlierer = Contact::create(['first_name' => 'Anna', 'last_name' => 'Verschwindet']);

    app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    expect(ContactMerge::query()->firstOrFail()->snapshot)->not->toBeNull();

    app(Betroffenenrechte::class)->loeschung($gewinner);

    // Der Vorgang verschwindet mit dem Gewinner; entscheidend ist, dass kein
    // Sicherungsstand uebrig bleibt -- er traegt die Felder des Verlierers.
    expect(ContactMerge::query()->whereNotNull('snapshot')->count())->toBe(0);
});

it('hinterlaesst einen Nachweis, der den Kontakt ueberlebt', function (): void {
    [, $kontakt] = vollstaendigePerson();
    $kennung = $kontakt->getKey();

    $vorgang = app(Betroffenenrechte::class)->loeschung($kontakt);

    $frisch = $vorgang->fresh();

    expect(Contact::query()->count())->toBe(0)
        ->and($frisch)->not->toBeNull()
        ->and($frisch?->type)->toBe(DataSubjectRequestType::Deletion)
        ->and($frisch?->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($frisch?->contact_id)->toBe($kennung)
        // Zahlen, keine Daten.
        ->and($frisch?->result['appointments'] ?? null)->toBe(1)
        ->and(json_encode($frisch?->result))->not->toContain('Kommt immer');
});

it('liefert eine Auskunft in lesbarer Form', function (): void {
    [$szenario, $kontakt] = vollstaendigePerson();

    $export = app(Auskunft::class)->fuerKontakt($kontakt);

    expect($export['person']['nachname'])->toBe($kontakt->last_name)
        ->and($export['kanaele'][0]['kanal'])->toBe('WhatsApp')
        // Die kanonische Form, nicht die Rohdaten.
        ->and($export['kanaele'][0]['kennung'])->toBe('+49 170 1234567')
        ->and($export['einwilligungen'][0]['zweck'])->toBe('WhatsApp')
        ->and($export['einwilligungen'][0]['text'])->toBe('Ja.')
        ->and($export['termine'][0]['terminart'])->toBe($szenario->aufbau->art->name)
        ->and($export['termine'][0]['behandler'])->toBe($szenario->aufbau->behandler->name())
        ->and($export['notizen'])->toHaveCount(2)
        ->and($export['schlagworte'])->toBe(['Stammkundin'])
        ->and($export['anhaenge'])->toHaveCount(2)
        ->and(array_column($export['anhaenge'], 'dateiname'))
        ->toContain('foto.jpg')
        ->toContain('einwilligung.pdf');
});

it('haelt im Vorgang zur Auskunft nur Zahlen fest', function (): void {
    // Eine gespeicherte Auskunft waere eine zweite Kopie aller Daten der
    // Person.
    [, $kontakt] = vollstaendigePerson();

    $vorgang = app(Betroffenenrechte::class)->auskunft($kontakt);

    expect($vorgang->result['termine'] ?? null)->toBe(1)
        ->and($vorgang->result['notizen'] ?? null)->toBe(2)
        ->and(json_encode($vorgang->result))->not->toContain($kontakt->last_name);
});

it('berichtigt und protokolliert dabei nur die Feldnamen', function (): void {
    [, $kontakt] = vollstaendigePerson();

    $vorgang = app(Betroffenenrechte::class)->berichtigung($kontakt, [
        'last_name' => 'Neuername',
        'email' => 'neu@praxis.test',
    ]);

    expect($kontakt->fresh()?->last_name)->toBe('Neuername')
        ->and($vorgang->result['felder'] ?? [])->toContain('last_name')
        ->and(json_encode($vorgang->result))->not->toContain('Neuername');
});

it('verlaesst bei einer Loeschung nie die Organisation', function (): void {
    $erste = alsMandant();
    [, $kontakt] = vollstaendigePerson();

    $zweite = alsMandant(organisation('Zweite Praxis'));
    $fremder = Contact::create(['first_name' => 'Fremd', 'last_name' => 'Bleibt']);

    app(TenantContext::class)->runAs(
        $erste,
        fn () => app(Betroffenenrechte::class)->loeschung($kontakt),
    );

    alsMandant($zweite);

    expect(Contact::query()->count())->toBe(1)
        ->and($fremder->fresh())->not->toBeNull();
});

it('zaehlt den Vorgang zu den offenen Betroffenenrechten', function (): void {
    [, $kontakt] = vollstaendigePerson();

    app(Betroffenenrechte::class)->auskunft($kontakt);

    expect(DataSubjectRequest::query()->count())->toBe(1);
});
