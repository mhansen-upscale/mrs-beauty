<?php

declare(strict_types=1);

use App\Kalender\Eigenmarkierung;
use App\Kalender\Rueckabgleich;
use App\Models\AppointmentSlot;
use App\Models\ExternalCalendarBlock;
use App\Support\Uuid;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Graphattrappe;
use Tests\Feature\Kalender\Graphaufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-15, Abnahmekriterien 4 bis 16 -- was Microsoft anders macht
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

function graphZeit(string $uhrzeit): string
{
    return Szenario::TAG.'T'.$uhrzeit.'.0000000';
}

function graphBelegt(string $vonUhr, string $bisUhr): int
{
    return AppointmentSlot::query()
        ->whereNotNull('external_block_id')
        ->where('starts_at', '>=', CarbonImmutable::parse(Szenario::TAG.' '.$vonUhr, 'UTC'))
        ->where('starts_at', '<', CarbonImmutable::parse(Szenario::TAG.' '.$bisUhr, 'UTC'))
        ->count();
}

it('wertet die mitgelieferte Zone aus und nicht die des Servers', function (): void {
    // **Der gefaehrlichste Unterschied zu Google.** "09:00" ohne Versatz ist
    // fuer sich genommen mehrdeutig -- erst das Feld timeZone macht daraus
    // einen Zeitpunkt. Wer es uebersieht, legt den Blocker eine Stunde daneben.
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('10:00:00'), graphZeit('11:00:00'), 'Europe/Berlin'),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $block = ExternalCalendarBlock::query()->firstOrFail();

    expect($block->starts_at->toIso8601String())->toBe('2027-01-13T09:00:00+00:00')
        ->and($block->ends_at->toIso8601String())->toBe('2027-01-13T10:00:00+00:00')
        ->and(graphBelegt('09:00:00', '10:00:00'))->toBe(12);
});

it('liest dasselbe Ereignis in zwei Zonen auf denselben Zeitraum', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-berlin', graphZeit('10:00:00'), graphZeit('11:00:00'), 'Europe/Berlin'),
    ];
    app(Rueckabgleich::class)->fuer($aufbau->verbindung);
    $berlin = ExternalCalendarBlock::query()->firstOrFail()->starts_at;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-utc', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC'),
    ];
    app(Rueckabgleich::class)->fuer($aufbau->verbindung->refresh());

    $utc = ExternalCalendarBlock::query()->where('external_id', 'ms-utc')->firstOrFail()->starts_at;

    expect($utc->toIso8601String())->toBe($berlin->toIso8601String());
});

it('faellt auf die Zone des Kalenders zurueck, wenn Graph eine Windows-Zone nennt', function (): void {
    // Graph nennt die Zone gelegentlich in Windows-Schreibweise, die PHP nicht
    // kennt. Dann gilt der bekannte Standort -- nicht stillschweigend UTC.
    $aufbau = new Graphaufbau(zone: 'Europe/Berlin');

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('10:00:00'), graphZeit('11:00:00'), 'W. Europe Standard Time'),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect(ExternalCalendarBlock::query()->firstOrFail()->starts_at->toIso8601String())
        ->toBe('2027-01-13T09:00:00+00:00');
});

it('deckt mit einem ganztaegigen Ereignis den Arbeitstag ab', function (): void {
    $aufbau = new Graphaufbau;

    // Graph kennzeichnet ganztaegig und liefert Mitternacht in der Zone des
    // Kalenders -- das Ende wieder ausschliessend.
    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis(
            'ms-ganztag',
            '2027-01-13T00:00:00.0000000',
            '2027-01-14T00:00:00.0000000',
            'Europe/Berlin',
            ['isAllDay' => true],
        ),
    ];

    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $block = ExternalCalendarBlock::query()->firstOrFail();

    expect($block->is_all_day)->toBeTrue()
        ->and($block->starts_at->toIso8601String())->toBe('2027-01-12T23:00:00+00:00')
        ->and(graphBelegt('08:00:00', '16:00:00'))->toBe(96);
});

it('traegt in ein ausgehendes Event eine Open Extension', function (): void {
    $aufbau = new Graphaufbau;

    app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    expect($aufbau->graph->angelegt)->toHaveCount(1);

    $ereignis = $aufbau->graph->angelegt[0];
    $erweiterung = $ereignis['extensions'][0];

    expect($erweiterung['@odata.type'])->toBe('microsoft.graph.openTypeExtension')
        ->and($erweiterung['extensionName'])->toBe(Eigenmarkierung::schluessel())
        ->and($erweiterung[Eigenmarkierung::schluessel()])->toBe((string) $aufbau->organisation->uuid)
        // R2: neutraler Titel, und die Zeit ohne Versatz neben ihrer Zone.
        ->and($ereignis['subject'])->toBe('Beratung')
        ->and($ereignis['start']['timeZone'])->toBe('Europe/Berlin')
        ->and($ereignis['start']['dateTime'])->not->toContain('+')
        ->and(json_encode($ereignis, JSON_UNESCAPED_UNICODE))
        ->not->toContain($aufbau->szenario->kontakt->last_name);
});

it('erzeugt aus einem eigenen Event keinen Blocker, auch ohne Erweiterung', function (): void {
    // **Die zweite Haelfte von R1.** Die Delta-Abfrage von Graph traegt keine
    // Erweiterungen -- die Marke am Event ist auf diesem Weg gar nicht lesbar.
    // Was wir selbst geschrieben haben, wissen wir trotzdem.
    $aufbau = new Graphaufbau;
    $vorschlag = $aufbau->szenario->vorschlag();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    // Genau die Kennung, die unser eigener Eintrag drueben bekommen hat --
    // und ohne jede Erweiterung, so wie das Delta sie liefert.
    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis(
            'graph-1',
            $vorschlag->blockedFrom->format('Y-m-d\TH:i:s').'.0000000',
            $vorschlag->blockedUntil->format('Y-m-d\TH:i:s').'.0000000',
        ),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebersprungen)->toBe(1)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0)
        ->and(AppointmentSlot::query()->where('appointment_id', $termin->getKey())->whereNotNull('external_block_id')->count())
        ->toBe(0);
});

it('erkennt die Eigenmarkierung auch an der Erweiterung', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC', [
            'extensions' => [[
                'extensionName' => Eigenmarkierung::schluessel(),
                Eigenmarkierung::schluessel() => (string) $aufbau->organisation->uuid,
            ]],
        ]),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->uebersprungen)->toBe(1)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0);
});

it('erzeugt aus dem markierten Event einer anderen Praxis sehr wohl einen Blocker', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC', [
            'extensions' => [[
                'extensionName' => Eigenmarkierung::schluessel(),
                Eigenmarkierung::schluessel() => Uuid::toString(Uuid::generate()),
            ]],
        ]),
    ];

    expect(app(Rueckabgleich::class)->fuer($aufbau->verbindung)->uebernommen)->toBe(1);
});

it('uebernimmt aus einem Event nur den Zeitraum', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC', [
            'subject' => 'Nasenkorrektur Frau Berger',
            'bodyPreview' => 'Nachkontrolle',
        ]),
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

    expect($treffer)->toBeEmpty('Der Originaltitel steht in der Datenbank: '.implode(', ', $treffer));
});

it('merkt sich den Delta-Zeiger und benutzt ihn beim naechsten Mal', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC'),
    ];

    $erster = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($erster->voll)->toBeTrue()
        ->and($aufbau->graph->letzterZeiger)->toBeNull()
        ->and($aufbau->verbindung->fresh()?->sync_token)->toBe($aufbau->graph->deltaLink);

    $aufbau->graph->ereignisse = [];

    $zweiter = app(Rueckabgleich::class)->fuer($aufbau->verbindung->refresh());

    expect($zweiter->voll)->toBeFalse()
        ->and($aufbau->graph->letzterZeiger)->toContain('deltatoken')
        ->and(ExternalCalendarBlock::query()->count())->toBe(1);
});

it('macht aus resyncRequired einen Vollabgleich und keinen Fehler', function (): void {
    $aufbau = new Graphaufbau;
    $aufbau->verbindung->sync_token = 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=alt';
    $aufbau->verbindung->save();

    $aufbau->graph->zeigerVerfallen = true;
    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC'),
    ];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->voll)->toBeTrue()
        ->and($ergebnis->uebernommen)->toBe(1)
        ->and($aufbau->graph->abrufe)->toBe(2)
        ->and($aufbau->verbindung->fresh()?->status->istAktiv())->toBeTrue();
});

it('entfernt den Blocker eines entfernten Eintrags', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC'),
    ];
    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect(graphBelegt('09:00:00', '10:00:00'))->toBe(12);

    // Im Delta kommt eine Loeschung als Eintrag mit '@removed' -- ohne Zeiten.
    $aufbau->graph->ereignisse = [['id' => 'ms-1', '@removed' => ['reason' => 'deleted']]];

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung->refresh());

    expect($ergebnis->entfernt)->toBe(1)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0)
        ->and(graphBelegt('09:00:00', '10:00:00'))->toBe(0);
});

it('erzeugt aus einem als frei markierten Ereignis keinen Blocker', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC', ['showAs' => 'free']),
    ];

    expect(app(Rueckabgleich::class)->fuer($aufbau->verbindung)->uebernommen)->toBe(0)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0);
});

it('erzeugt aus einer abgesagten Besprechung keinen Blocker', function (): void {
    $aufbau = new Graphaufbau;

    $aufbau->graph->ereignisse = [
        Graphattrappe::ereignis('ms-1', graphZeit('09:00:00'), graphZeit('10:00:00'), 'UTC', ['isCancelled' => true]),
    ];

    expect(app(Rueckabgleich::class)->fuer($aufbau->verbindung)->uebernommen)->toBe(0);
});
