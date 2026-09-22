<?php

declare(strict_types=1);

use App\Enums\HoldPurpose;
use App\Kalender\Eigenmarkierung;
use App\Kalender\Rueckabgleich;
use App\Models\AppointmentSlot;
use App\Models\ExternalCalendarBlock;
use App\Support\Uuid;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Googleattrappe;
use Tests\Feature\Kalender\Kalenderaufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-14, Abnahmekriterien 6 bis 20 -- was von aussen hereinkommt
|--------------------------------------------------------------------------
|
| Enthaelt die Testfaelle 18, 19 und 20 aus docs/fachlogik/verfuegbarkeit.md,
| die WP-10 ausdruecklich hierher verschoben hat.
|
*/

beforeEach(function (): void {
    // Der Vortag des Szenariotages. Der Rueckabgleich rechnet sein Fenster ab
    // "jetzt" -- ohne Zeitreise laege der Testtag im Jahr 2027 darin nicht.
    travelTo(reisezeit());
});

/** Der Zeitpunkt, an dem in diesen Tests "jetzt" ist. */
function reisezeit(): CarbonImmutable
{
    return CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC');
}

/** Ein Slot des Testtages in UTC. */
function slotZeit(string $uhrzeit): CarbonImmutable
{
    return CarbonImmutable::parse(Szenario::TAG.' '.$uhrzeit, 'UTC');
}

function belegteSlots(CarbonImmutable $von, CarbonImmutable $bis): int
{
    return AppointmentSlot::query()
        ->whereNotNull('external_block_id')
        ->where('starts_at', '>=', $von)
        ->where('starts_at', '<', $bis)
        ->count();
}

it('macht eine externe Zeit unbuchbar', function (): void {
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('extern-1', slotZeit('09:00:00')->toRfc3339String(), slotZeit('10:00:00')->toRfc3339String()),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebernommen)->toBe(1)
        ->and(belegteSlots(slotZeit('09:00:00'), slotZeit('10:00:00')))->toBe(12);

    $vorschlaege = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->szenario->aufbau->art,
        von: slotZeit('00:00:00'),
        bis: slotZeit('00:00:00')->addDay(),
        jetzt: reisezeit(),
    );

    $startzeiten = array_map(fn ($v): string => $v->startsAt->format('H:i'), $vorschlaege);

    expect($startzeiten)->not->toContain('09:00')
        ->and($startzeiten)->not->toContain('09:30')
        ->and($startzeiten)->toContain('10:00');
});

it('erzeugt aus einem eigenmarkierten Event keinen Blocker', function (): void {
    // Testfall 19 aus docs/fachlogik/verfuegbarkeit.md.
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'eigen-1',
            slotZeit('09:00:00')->toRfc3339String(),
            slotZeit('10:00:00')->toRfc3339String(),
            ['extendedProperties' => ['private' => [
                Eigenmarkierung::schluessel() => (string) $aufbau->organisation->uuid,
                Eigenmarkierung::schluessel().'_appointment' => (string) Uuid::toString(Uuid::generate()),
            ]]],
        ),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebersprungen)->toBe(1)
        ->and($ergebnis->uebernommen)->toBe(0)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0)
        ->and(belegteSlots(slotZeit('09:00:00'), slotZeit('10:00:00')))->toBe(0);
});

it('erzeugt aus dem markierten Event einer anderen Praxis sehr wohl einen Blocker', function (): void {
    // Ein Behandler kann fuer zwei Praxen arbeiten und denselben Kalender
    // verbinden. Das Event der einen ist fuer die andere echte belegte Zeit --
    // wer nur auf den Schluessel prueft, bucht hier doppelt.
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'fremd-1',
            slotZeit('09:00:00')->toRfc3339String(),
            slotZeit('10:00:00')->toRfc3339String(),
            ['extendedProperties' => ['private' => [
                Eigenmarkierung::schluessel() => (string) Uuid::toString(Uuid::generate()),
            ]]],
        ),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebernommen)->toBe(1)
        ->and($ergebnis->uebersprungen)->toBe(0);
});

it('uebernimmt aus einem Event nur den Zeitraum', function (): void {
    // R2: der Originaltitel landet nirgends in der Datenbank. Der Nachweis
    // durchsucht **jede** Tabelle -- eine Spalte, die es nicht gibt, ist die
    // einzige Zusicherung, die sich nicht spaeter aufweichen laesst.
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'extern-1',
            slotZeit('09:00:00')->toRfc3339String(),
            slotZeit('10:00:00')->toRfc3339String(),
            ['summary' => 'Nasenkorrektur Frau Berger', 'description' => 'Nachkontrolle'],
        ),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $treffer = [];

    foreach (DB::select('show tables') as $zeile) {
        $tabelle = (string) array_values((array) $zeile)[0];

        foreach (DB::table($tabelle)->get() as $datensatz) {
            foreach ((array) $datensatz as $spalte => $wert) {
                if (is_string($wert) && str_contains($wert, 'Nasenkorrektur')) {
                    $treffer[] = "{$tabelle}.{$spalte}";
                }
            }
        }
    }

    expect($treffer)->toBeEmpty(
        'Der Originaltitel eines externen Events steht in der Datenbank: '.implode(', ', $treffer)
    );
});

it('laesst einen Termin unberuehrt, ueber dem ein externer Blocker liegt', function (): void {
    // Testfall 20, erste Haelfte. R3/B6: der externe Kalender gewinnt bei
    // Blockern, das System gewinnt bei Terminen.
    $aufbau = new Kalenderaufbau;
    $vorschlag = $aufbau->szenario->vorschlag();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    // **Ein fremdes Event.** Die Kennung darf nicht die sein, die unser
    // eigener Eintrag drueben bekommen hat -- sonst prueft der Test die
    // Eigenmarkierung statt der Konfliktregel.
    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'fremd-1',
            $vorschlag->blockedFrom->toRfc3339String(),
            $vorschlag->blockedUntil->toRfc3339String(),
        ),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $slots = AppointmentSlot::query()->where('appointment_id', $termin->getKey())->get();

    expect($slots)->not->toBeEmpty()
        ->and($slots->whereNotNull('external_block_id'))->toBeEmpty()
        // Der Blocker verschwindet nicht -- das Team sieht beides.
        ->and(ExternalCalendarBlock::query()->count())->toBe(1)
        ->and($termin->fresh()?->status)->toBe($termin->status);
});

it('gibt einem gehaltenen Slot keinen Blocker', function (): void {
    $aufbau = new Kalenderaufbau;
    $vorschlag = $aufbau->szenario->vorschlag();

    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::Internal, jetzt: reisezeit());

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'extern-1',
            $vorschlag->blockedFrom->toRfc3339String(),
            $vorschlag->blockedUntil->toRfc3339String(),
        ),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $gehalten = AppointmentSlot::query()->where('slot_hold_id', $hold->getKey())->get();

    expect($gehalten)->not->toBeEmpty()
        ->and($gehalten->whereNotNull('external_block_id'))->toBeEmpty();
});

it('verarbeitet ein Delta und merkt sich das neue Token', function (): void {
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('extern-1', slotZeit('09:00:00')->toRfc3339String(), slotZeit('10:00:00')->toRfc3339String()),
    ];

    $erster = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($erster->voll)->toBeTrue()
        ->and($aufbau->google->letztesToken)->toBeNull()
        ->and($aufbau->verbindung->fresh()?->sync_token)->toBe('sync-2');

    $aufbau->google->ereignisse = [];
    $aufbau->google->syncToken = 'sync-3';

    $zweiter = app(Rueckabgleich::class)->fuer($aufbau->verbindung->refresh());

    expect($zweiter->voll)->toBeFalse()
        ->and($aufbau->google->letztesToken)->toBe('sync-2')
        ->and($aufbau->verbindung->fresh()?->sync_token)->toBe('sync-3')
        // Ein Delta ohne Aenderungen raeumt nichts ab.
        ->and(ExternalCalendarBlock::query()->count())->toBe(1);
});

it('macht aus einem verfallenen Sync-Token einen Vollabgleich und keinen Fehler', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->verbindung->sync_token = 'sync-alt';
    $aufbau->verbindung->save();

    $aufbau->google->tokenVerfallen = true;
    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('extern-1', slotZeit('09:00:00')->toRfc3339String(), slotZeit('10:00:00')->toRfc3339String()),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->voll)->toBeTrue()
        ->and($ergebnis->uebernommen)->toBe(1)
        // Zwei Abrufe: der erste mit Token und 410, der zweite ohne.
        ->and($aufbau->google->abrufe)->toBe(2)
        ->and($aufbau->verbindung->fresh()?->status->istAktiv())->toBeTrue();
});

it('entfernt den Blocker eines extern abgesagten Events', function (): void {
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('extern-1', slotZeit('09:00:00')->toRfc3339String(), slotZeit('10:00:00')->toRfc3339String()),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect(belegteSlots(slotZeit('09:00:00'), slotZeit('10:00:00')))->toBe(12);

    // Eine Absage kommt im Delta ohne Zeiten.
    $aufbau->google->ereignisse = [['id' => 'extern-1', 'status' => 'cancelled']];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung->refresh());

    expect($ergebnis->entfernt)->toBe(1)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0)
        ->and(belegteSlots(slotZeit('09:00:00'), slotZeit('10:00:00')))->toBe(0);
});

it('wertet ein ganztaegiges Event in der Zeitzone des Kalenders aus', function (): void {
    // Ganztaegig kommt als `date`, also ohne Zone. In Europe/Berlin beginnt
    // der 13. Januar um 23:00 UTC des Vortages -- wer UTC annimmt, legt den
    // Blocker eine Stunde daneben und laesst 23:00 bis 00:00 offen.
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [[
        'id' => 'ganztag-1',
        'status' => 'confirmed',
        'summary' => 'Fortbildung',
        'start' => ['date' => Szenario::TAG],
        'end' => ['date' => '2027-01-14'],
    ]];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $block = ExternalCalendarBlock::query()->firstOrFail();

    expect($block->is_all_day)->toBeTrue()
        ->and($block->starts_at->toIso8601String())->toBe('2027-01-12T23:00:00+00:00')
        ->and($block->ends_at->toIso8601String())->toBe('2027-01-13T23:00:00+00:00')
        // Der ganze Arbeitstag ist damit belegt: 08:00 bis 16:00 UTC.
        ->and(belegteSlots(slotZeit('08:00:00'), slotZeit('16:00:00')))->toBe(96);
});

it('liest ein Event mit eigener Zeitzone nicht in der Serverzeit', function (): void {
    $aufbau = new Kalenderaufbau;

    // 11:00 in Berlin ist 10:00 UTC. Stuende hier "10:00" ohne Versatz,
    // begaenne der Blocker eine Stunde zu frueh.
    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('extern-1', '2027-01-13T11:00:00+01:00', '2027-01-13T12:00:00+01:00'),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $block = ExternalCalendarBlock::query()->firstOrFail();

    expect($block->starts_at->toIso8601String())->toBe('2027-01-13T10:00:00+00:00')
        ->and($block->is_all_day)->toBeFalse();
});

it('erzeugt aus einem als frei markierten Event keinen Blocker', function (): void {
    $aufbau = new Kalenderaufbau;

    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis(
            'frei-1',
            slotZeit('09:00:00')->toRfc3339String(),
            slotZeit('10:00:00')->toRfc3339String(),
            ['transparency' => 'transparent'],
        ),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebernommen)->toBe(0)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0);
});

it('meldet einen entzogenen Zugang als Ausfall, statt es erneut zu versuchen', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->google->zugangEntzogen = true;

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $frisch = $aufbau->verbindung->fresh();

    expect($ergebnis->unterbrochen)->toBeTrue()
        ->and($frisch?->status->brauchtAufmerksamkeit())->toBeTrue()
        ->and($frisch?->last_error)->toBe('access_revoked');
});
