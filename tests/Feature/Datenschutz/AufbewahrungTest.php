<?php

declare(strict_types=1);

use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\Aufbewahrung;
use App\Enums\AttachmentContext;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\RetentionSubject;
use App\Kontakte\Zusammenfuehrung;
use App\Leads\Leadverwaltung;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\Lead;
use App\Models\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-18 -- Aufbewahrung (Entscheidung C7)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Storage::fake('local');

    app(Aufbewahrung::class)->richteEin();
});

/** Eine Anfrage, die vor der Frist entstanden ist. */
function alteAnfrage(LeadStatus $status, string $name): Lead
{
    $kontakt = Contact::create(['first_name' => 'Alt', 'last_name' => $name]);

    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);
    $lead->status = $status;
    $lead->save();

    // Am Modell vorbei, damit created_at wirklich alt ist.
    Lead::query()->whereKey($lead->getKey())->update([
        'created_at' => CarbonImmutable::now()->subDays(400),
    ]);

    return $lead->refresh();
}

it('legt die Standardfristen an', function (): void {
    expect(RetentionPolicy::query()->count())->toBe(count(RetentionSubject::cases()))
        ->and(RetentionPolicy::query()->where('subject', 'chat_attachment')->value('retention_days'))->toBe(90);
});

it('aendert in der Vorschau nichts', function (): void {
    // Ein Lauf, der beim ersten scharfen Durchgang zu viel loescht, ist nicht
    // rueckholbar.
    alteAnfrage(LeadStatus::New, 'Vorschau');

    $ergebnis = app(Aufbewahrung::class)->lauf(vorschau: true);

    expect($ergebnis->vorschau)->toBeTrue()
        ->and($ergebnis->nachGegenstand()['lead_without_appointment'])->toBe(1)
        ->and(Lead::query()->count())->toBe(1);
});

it('zaehlt in der Vorschau dasselbe wie im Ernstfall', function (): void {
    // Eine Vorschau, die anders zaehlt als der Ernstfall, ist keine.
    alteAnfrage(LeadStatus::New, 'Eins');
    alteAnfrage(LeadStatus::Lost, 'Zwei');

    $vorschau = app(Aufbewahrung::class)->lauf(vorschau: true)->nachGegenstand();
    $scharf = app(Aufbewahrung::class)->lauf(vorschau: false)->nachGegenstand();

    expect($scharf['lead_without_appointment'])->toBe($vorschau['lead_without_appointment']);
});

it('loescht eine Anfrage ohne Termin und behaelt eine mit', function (): void {
    alteAnfrage(LeadStatus::New, 'OhneTermin');
    alteAnfrage(LeadStatus::Scheduled, 'MitTermin');
    alteAnfrage(LeadStatus::Won, 'Gewonnen');

    app(Aufbewahrung::class)->lauf(vorschau: false);

    $verblieben = Lead::query()->get()->map(fn (Lead $lead): string => $lead->status->value)->sort()->values()->all();

    expect($verblieben)->toBe(['scheduled', 'won']);
});

it('nimmt beim Loeschen eines Chat-Anhangs die Datei mit', function (): void {
    $kontakt = Contact::create(['first_name' => 'Chat', 'last_name' => 'Anhang']);

    $anhang = app(Anhangspeicher::class)->lege($kontakt, 'foto', 'foto.jpg', AttachmentContext::Chat);
    $pfad = $anhang->path;

    travelTo(CarbonImmutable::now()->addDays(91));

    app(Aufbewahrung::class)->lauf(vorschau: false);

    expect(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($pfad))->toBeFalse();
});

it('laesst ein Dokument stehen', function (): void {
    $kontakt = Contact::create(['first_name' => 'Doku', 'last_name' => 'Bleibt']);
    app(Anhangspeicher::class)->lege($kontakt, 'inhalt', 'vertrag.pdf', AttachmentContext::Document);

    travelTo(CarbonImmutable::now()->addDays(400));

    app(Aufbewahrung::class)->lauf(vorschau: false);

    expect(Attachment::query()->count())->toBe(1);
});

it('entfernt den Sicherungsstand, nicht den Vorgang', function (): void {
    $gewinner = Contact::create(['first_name' => 'Merge', 'last_name' => 'Eins']);
    $verlierer = Contact::create(['first_name' => 'Merge', 'last_name' => 'Zwei']);

    $vorgang = app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    travelTo(CarbonImmutable::now()->addDays(31));

    app(Aufbewahrung::class)->lauf(vorschau: false);

    $frisch = $vorgang->fresh();

    expect(ContactMerge::query()->count())->toBe(1)
        ->and($frisch?->snapshot)->toBeNull()
        ->and($frisch?->istUmkehrbar())->toBeFalse();
});

it('loescht altes Protokoll, obwohl es append-only ist', function (): void {
    // WP-05 hat die Tuer vorgesehen und verschlossen gelassen: ein Trigger
    // verhindert jedes DELETE ausser dem des Aufbewahrungsjobs.
    // Das Protokoll laesst sich auch nicht **aendern** -- alte Eintraege
    // entstehen deshalb in der Vergangenheit, nicht durch ein UPDATE.
    $jetzt = CarbonImmutable::now();
    travelTo($jetzt->subDays(1100));

    Contact::create(['first_name' => 'Protokoll', 'last_name' => 'Alt']);

    expect(AuditLog::query()->count())->toBeGreaterThan(0);

    $alte = AuditLog::query()->count();

    travelTo($jetzt);

    // Die Fristen dieses Aufbaus haben selbst Eintraege erzeugt -- die sind
    // jung und bleiben. Weg muss, was aelter ist als die Frist.
    app(Aufbewahrung::class)->lauf(vorschau: false);

    expect(AuditLog::query()->where('occurred_at', '<=', $jetzt->subDays(1095))->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBeLessThan($alte + 5);
});

it('laesst das Protokoll ausserhalb des Jobs unantastbar', function (): void {
    // Die Gegenprobe: ohne die Tuer bleibt append-only append-only.
    Contact::create(['first_name' => 'Protokoll', 'last_name' => 'Fest']);

    expect(fn () => AuditLog::query()->delete())->toThrow(QueryException::class);
});

it('laeuft ueber den Befehl in der Vorschau', function (): void {
    alteAnfrage(LeadStatus::New, 'Befehl');

    expect(Artisan::call('mrs:aufbewahrung'))->toBe(0)
        ->and(Artisan::output())->toContain('Vorschau')
        ->and(Lead::query()->count())->toBe(1);
});

it('loescht ueber den Befehl nur mit --scharf', function (): void {
    alteAnfrage(LeadStatus::New, 'Scharf');

    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true]))->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('haelt sich an eine geaenderte Frist', function (): void {
    $kontakt = Contact::create(['first_name' => 'Frist', 'last_name' => 'Kurz']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);

    Lead::query()->whereKey($lead->getKey())->update([
        'created_at' => CarbonImmutable::now()->subDays(40),
    ]);

    // Mit der Standardfrist von 365 Tagen passiert nichts ...
    app(Aufbewahrung::class)->lauf(vorschau: false);
    expect(Lead::query()->count())->toBe(1);

    // ... mit dreissig Tagen schon.
    RetentionPolicy::query()
        ->where('subject', RetentionSubject::LeadWithoutAppointment->value)
        ->update(['retention_days' => 30]);

    app(Aufbewahrung::class)->lauf(vorschau: false);

    expect(Lead::query()->count())->toBe(0);
});
