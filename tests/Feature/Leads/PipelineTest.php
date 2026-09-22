<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\CancellationReason;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Leads\Leadverwaltung;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Treatment;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-17, Abnahmekriterien 1 bis 18 -- Entstehung, Pipeline, Kennzahlen
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function jetzt(): CarbonImmutable
{
    return CarbonImmutable::now();
}

it('erzeugt aus einer Anfrage einen Lead und laesst den Kontakt in Ruhe', function (): void {
    // Entscheidung D3: die Anfrage ist eine eigene Einheit, kein Zustand am
    // Kontakt.
    $kontakt = Contact::create(['first_name' => 'Anna', 'last_name' => 'Falk']);
    $behandlung = Treatment::factory()->create();

    $lead = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Phone, jetzt());

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->contact_id)->toBe($kontakt->getKey())
        ->and($lead->treatment_id)->toBe($behandlung->getKey())
        ->and(Contact::query()->count())->toBe(1);
});

it('erzeugt zur selben Behandlung keinen zweiten offenen Lead', function (): void {
    $kontakt = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);
    $behandlung = Treatment::factory()->create();

    $erster = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt());
    $zweiter = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Phone, jetzt()->addHour());

    expect($zweiter->getKey())->toBe($erster->getKey())
        ->and(Lead::query()->count())->toBe(1)
        // Ein Lebenszeichen: die Frist aus D4 zaehlt ab der letzten Aktivitaet.
        ->and($zweiter->last_activity_at->toIso8601String())->toBe(jetzt()->addHour()->toIso8601String());
});

it('erzeugt zu einer abweichenden Behandlung einen zweiten Lead', function (): void {
    $kontakt = Contact::create(['first_name' => 'Cem', 'last_name' => 'Yildiz']);

    app(Leadverwaltung::class)->erfasse($kontakt, Treatment::factory()->create(), LeadSource::Message, jetzt());
    app(Leadverwaltung::class)->erfasse($kontakt, Treatment::factory()->create(), LeadSource::Message, jetzt());

    expect(Lead::query()->count())->toBe(2);
});

it('erzeugt nach der Frist ohne Aktivitaet einen neuen Lead', function (): void {
    $kontakt = Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth']);
    $behandlung = Treatment::factory()->create();

    app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt());

    $spaeter = jetzt()->addDays(91);
    app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, $spaeter);

    expect(Lead::query()->count())->toBe(2);
});

it('haelt innerhalb der Frist an einem Vorgang fest', function (): void {
    $kontakt = Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart']);
    $behandlung = Treatment::factory()->create();

    app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt());
    app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt()->addDays(89));

    expect(Lead::query()->count())->toBe(1);
});

it('laesst einen geschlossenen Lead keinen neuen blockieren', function (): void {
    $kontakt = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $behandlung = Treatment::factory()->create();

    $erster = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt());
    app(Leadverwaltung::class)->gibAuf($erster, LeadLostReason::TooExpensive, jetzt());

    app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt()->addDay());

    expect(Lead::query()->count())->toBe(2);
});

it('nimmt die Frist aus der Konfiguration', function (): void {
    expect(config('mrs.leads.reopen_after_inactive_days'))->toBe(90);
});

it('fuehrt keine Freitextspalte fuer den Behandlungswunsch', function (): void {
    // Entscheidung D2 als Schema, nicht als Vorsatz: ein Textfeld an dieser
    // Stelle fuellt sich mit Angaben nach Artikel 9 DSGVO und landet dann in
    // Logs, Kalendertiteln und Meta-Payloads (Regel 2).
    $texte = collect(Schema::getColumns('leads'))
        ->filter(fn (array $spalte): bool => in_array($spalte['type_name'], ['text', 'mediumtext', 'longtext', 'json'], true))
        ->pluck('name')
        ->all();

    expect($texte)->toBeEmpty('Diese Spalten laden zu Freitext ein: '.implode(', ', $texte));
});

it('laesst einen Lead ohne Behandlungswunsch zu', function (): void {
    // Die erste Nachricht lautet oft nur "Was kostet das?".
    $kontakt = Contact::create(['first_name' => 'Gerd', 'last_name' => 'Halm']);

    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message, jetzt());

    expect($lead->treatment_id)->toBeNull()
        ->and(Lead::query()->offen()->count())->toBe(1);
});

it('setzt den Lead auf Termin, wenn einer gebucht wird', function (): void {
    $szenario = new Szenario;

    app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    $lead = Lead::query()->firstOrFail();

    expect($lead->status)->toBe(LeadStatus::Scheduled)
        ->and($lead->source)->toBe(LeadSource::Other);
});

it('gewinnt den Lead erst, wenn jemand erschienen ist', function (): void {
    // Gewonnen heisst erschienen, nicht gebucht -- sonst misst der ROAS
    // Absichten statt Umsatz.
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    expect(Lead::query()->firstOrFail()->status)->toBe(LeadStatus::Scheduled);

    app(Terminplaner::class)->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $szenario->vorschlag()->endsAt->addHour());

    $lead = Lead::query()->firstOrFail();

    expect($lead->status)->toBe(LeadStatus::Won)
        ->and($lead->closed_at)->not->toBeNull();
});

it('gewinnt den Lead bei einem nicht wahrgenommenen Termin nicht', function (): void {
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    app(Terminplaner::class)->setzeStatus($termin, AppointmentStatus::NoShow, jetzt: $szenario->vorschlag()->endsAt->addHour());

    expect(Lead::query()->firstOrFail()->status)->not->toBe(LeadStatus::Won);
});

it('oeffnet den Lead nach einer Absage wieder', function (): void {
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    app(Terminplaner::class)->sageAb($termin, CancellationReason::Contact, jetzt: $szenario->jetzt());

    $lead = Lead::query()->firstOrFail();

    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->status->istOffen())->toBeTrue();
});

it('verlangt fuer verloren einen Grund', function (): void {
    $kontakt = Contact::create(['first_name' => 'Hanna', 'last_name' => 'Ruf']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Phone, jetzt());

    $verloren = app(Leadverwaltung::class)->gibAuf($lead, LeadLostReason::NoResponse, jetzt());

    expect($verloren->status)->toBe(LeadStatus::Lost)
        ->and($verloren->lost_reason)->toBe(LeadLostReason::NoResponse)
        ->and($verloren->closed_at)->not->toBeNull();
});

it('haelt die erste Reaktion in Sekunden fest', function (): void {
    $kontakt = Contact::create(['first_name' => 'Ina', 'last_name' => 'Schnell']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message, jetzt());

    $antwort = jetzt()->addMinutes(7);
    app(Leadverwaltung::class)->vermerkeReaktion($lead, $antwort);

    $frisch = $lead->fresh();

    expect($frisch?->first_response_seconds)->toBe(420)
        ->and($frisch?->status)->toBe(LeadStatus::Contacted);
});

it('aendert die erste Reaktion durch eine zweite nicht', function (): void {
    // Eine Kennzahl, die sich durch Nacharbeit schoenen laesst, ist keine.
    $kontakt = Contact::create(['first_name' => 'Jan', 'last_name' => 'Spaet']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message, jetzt());

    app(Leadverwaltung::class)->vermerkeReaktion($lead, jetzt()->addMinutes(7));
    app(Leadverwaltung::class)->vermerkeReaktion($lead->fresh() ?? $lead, jetzt()->addHours(3));

    expect(Lead::query()->firstOrFail()->first_response_seconds)->toBe(420);
});

it('laesst die erste Reaktion ohne Reaktion leer', function (): void {
    $kontakt = Contact::create(['first_name' => 'Kai', 'last_name' => 'Still']);
    app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message, jetzt());

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_response_seconds)->toBeNull()
        ->and($lead->first_responded_at)->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::New);
});

it('zaehlt als Abschluss genau die gewonnenen Leads', function (): void {
    $behandlung = Treatment::factory()->create();

    foreach ([LeadStatus::New, LeadStatus::Contacted, LeadStatus::Scheduled, LeadStatus::Won, LeadStatus::Lost] as $status) {
        $kontakt = Contact::create(['first_name' => 'Zahl', 'last_name' => $status->value]);
        $lead = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message, jetzt());
        $lead->status = $status;
        $lead->save();
    }

    expect(Lead::query()->where('status', LeadStatus::Won->value)->count())->toBe(1)
        ->and(Lead::query()->offen()->count())->toBe(3)
        ->and(Lead::query()->count())->toBe(5);
});

it('haelt den Kontakt und den Wunsch aus dem Protokoll heraus', function (): void {
    $kontakt = Contact::create(['first_name' => 'Lena', 'last_name' => 'Leise']);
    app(Leadverwaltung::class)->erfasse($kontakt, Treatment::factory()->create(), LeadSource::Phone, jetzt());

    $eintrag = DB::table('audit_logs')->where('subject_type', Lead::class)->first();

    expect($eintrag)->not->toBeNull();

    /** @var object{context: ?string} $eintrag */
    expect((string) $eintrag->context)->not->toContain('Leise');
});
