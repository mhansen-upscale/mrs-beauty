<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Enums\WaitlistStatus;
use App\Kanaele\Konversationen;
use App\Models\Appointment;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Practitioner;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| Kennzahlen auf dem Dashboard der Praxis
|--------------------------------------------------------------------------
|
| "Was heute wichtig ist" -- als Zahlen, nicht als Inhalte. **Jede Kennzahl
| sieht nur, wer die Sache dahinter sehen darf**: die Behandlerin ihre
| eigenen Termine, den Posteingang nur, wer ihn oeffnen darf, das Kontingent
| nur, wer das Abo verwaltet.
|
| **Heute und diese Woche sind die der Praxis**, nicht des Servers: gerechnet
| in der Ortszeit des Standorts (CLAUDE.md, Arbeitsweise).
|
*/

beforeEach(function (): void {
    // Dienstag, 09:00 in Berlin.
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Ein Termin zu einer Ortszeit in Berlin -- am Raster vorbei, es geht um das Zaehlen. */
function dashboardtermin(
    Szenario $szenario,
    string $ortszeit,
    BookingChannel $kanal = BookingChannel::Internal,
    ?AppointmentStatus $zustand = null,
): Appointment {
    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlagAb(CarbonImmutable::parse($ortszeit, 'Europe/Berlin')->utc()->toIso8601String()),
        Contact::factory()->create(),
        kanal: $kanal,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    );

    if ($zustand instanceof AppointmentStatus) {
        $termin->forceFill(['status' => $zustand])->save();
    }

    return $termin;
}

it('zaehlt die Termine von heute und dieser Woche in Ortszeit', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    dashboardtermin($szenario, '2027-01-10 23:30');   // Sonntag davor
    dashboardtermin($szenario, '2027-01-11 23:30');   // Montag -- diese Woche, nicht heute
    dashboardtermin($szenario, '2027-01-12 00:30');   // heute, in UTC noch Montag
    dashboardtermin($szenario, '2027-01-12 23:30');   // heute, in UTC schon 22:30
    dashboardtermin($szenario, '2027-01-12 11:00', zustand: AppointmentStatus::Cancelled);
    dashboardtermin($szenario, '2027-01-17 23:00');   // Sonntagabend
    dashboardtermin($szenario, '2027-01-18 00:30');   // naechster Montag

    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.termine.heute', 2)
            ->where('kennzahlen.termine.woche', 4)
        );
});

it('zeigt einer Behandlerin nur ihre eigenen Termine', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    dashboardtermin($szenario, '2027-01-12 10:00');
    dashboardtermin($szenario, '2027-01-12 14:00');

    $eigene = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $szenario->aufbau->behandler->forceFill(['user_id' => $eigene->getKey()])->save();

    $andere = User::factory()->fuer($organisation, Role::Practitioner)->create();
    Practitioner::factory()->create(['user_id' => $andere->getKey()]);

    $ohneKalender = User::factory()->fuer($organisation, Role::Practitioner)->create();

    actingAs($eigene)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.termine.heute', 2));

    actingAs($andere)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.termine.heute', 0));

    // Ohne verknuepften Behandler gibt es keinen eigenen Kalender -- und
    // keine Zahl, die so tut, als gaebe es einen.
    actingAs($ohneKalender)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.termine', null));
});

it('rechnet Selbstbuchungen und Nichterscheinen der letzten dreissig Tage', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    dashboardtermin($szenario, '2027-01-05 10:00', zustand: AppointmentStatus::Attended);
    dashboardtermin($szenario, '2027-01-06 10:00', BookingChannel::Public, AppointmentStatus::Attended);
    dashboardtermin($szenario, '2027-01-07 10:00', BookingChannel::Agent, AppointmentStatus::NoShow);
    dashboardtermin($szenario, '2027-01-08 10:00', zustand: AppointmentStatus::Attended);
    dashboardtermin($szenario, '2027-01-14 10:00', BookingChannel::Waitlist);

    // Liegt vor dem Zeitraum -- zaehlt beim Nichterscheinen nicht mit.
    dashboardtermin($szenario, '2026-12-01 10:00', zustand: AppointmentStatus::NoShow);

    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.buchungen.gebucht', 6)
            ->where('kennzahlen.buchungen.selbstGebucht', 3)
            ->where('kennzahlen.buchungen.erschienen', 3)
            ->where('kennzahlen.buchungen.nichtErschienen', 1)
        );
});

it('zeigt ungelesene Gespraeche nur, wer den Posteingang sehen darf', function (): void {
    $organisation = alsMandant();
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();
    $marketing = User::factory()->fuer($organisation, Role::Marketing)->create();

    $gespraech = fn (string $nummer) => app(Konversationen::class)->fuer(ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => $nummer,
    ]));

    $gespraech('4915110000001')->forceFill(['last_inbound_at' => CarbonImmutable::now()->subMinutes(5), 'last_read_at' => null])->save();
    $gespraech('4915110000002')->forceFill(['last_inbound_at' => CarbonImmutable::now()->subHour(), 'last_read_at' => CarbonImmutable::now()->subMinutes(30)])->save();
    $gespraech('4915110000003')->forceFill([
        'last_inbound_at' => CarbonImmutable::now()->subHour(),
        'last_read_at' => null,
        'status' => ConversationStatus::Closed,
    ])->save();

    actingAs($empfang)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.posteingang.ungelesen', 1));

    actingAs($marketing)->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.posteingang', null));
});

it('zaehlt neue Anfragen und aktive Wartelisteneintraege', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    foreach ([LeadStatus::New, LeadStatus::New, LeadStatus::Contacted] as $zustand) {
        Lead::query()->create([
            'contact_id' => Contact::factory()->create()->getKey(),
            'status' => $zustand->value,
            'source' => 'booking_page',
            'last_activity_at' => CarbonImmutable::now(),
        ]);
    }

    foreach ([WaitlistStatus::Active, WaitlistStatus::Active, WaitlistStatus::Expired] as $zustand) {
        $eintrag = new WaitlistEntry;
        $eintrag->contact_id = Contact::factory()->create()->getKey();
        $eintrag->appointment_type_id = $szenario->aufbau->art->getKey();
        $eintrag->status = $zustand;
        $eintrag->all_locations = true;
        $eintrag->earliest_date = CarbonImmutable::parse('2027-01-01');
        $eintrag->latest_date = CarbonImmutable::parse('2027-12-31');
        $eintrag->weekday_mask = 127;
        $eintrag->min_notice_hours = 0;
        $eintrag->priority = 0;
        $eintrag->expires_at = CarbonImmutable::parse('2027-12-31 23:59:59');
        $eintrag->save();
    }

    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.anfragen.neu', 2)
            ->where('kennzahlen.warteliste.aktiv', 2)
        );
});

it('zeigt das Kontingent nur der Inhaberin und legt dafuer kein Abo an', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($inhaberin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.kontingent.enthalten.nachrichten', (int) config('mrs.billing.included.messages'))
            ->where('kennzahlen.kontingent.rest.nachrichten', (int) config('mrs.billing.included.messages'))
            ->where('kennzahlen.kontingent.rest.agentenlaeufe', (int) config('mrs.billing.included.agent_runs'))
        );

    // **Ein Dashboard schreibt nichts** -- auch kein Abo in der Testphase,
    // wie es Kontingente::abo() beim ersten Zugriff anlegen wuerde.
    expect(Subscription::query()->count())->toBe(0);

    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.kontingent', null));
});

it('zeigt einer Praxis nur ihre eigenen Zahlen', function (): void {
    $fremde = alsMandant(organisation('Fremd'));
    $fremdesSzenario = new Szenario;
    dashboardtermin($fremdesSzenario, '2027-01-12 10:00');

    $eigene = alsMandant(organisation('Eigen'));
    $empfang = User::factory()->fuer($eigene, Role::Reception)->create();

    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.termine.heute', 0));

    expect($fremde->getKey())->not->toBe($eigene->getKey());
});
