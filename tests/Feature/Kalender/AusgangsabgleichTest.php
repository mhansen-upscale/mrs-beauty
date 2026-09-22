<?php

declare(strict_types=1);

use App\Enums\CalendarPrivacyMode;
use App\Enums\CancellationReason;
use App\Jobs\KalenderEventEntfernen;
use App\Jobs\KalenderEventSchreiben;
use App\Kalender\Eigenmarkierung;
use App\Kalender\Kalenderdienste;
use App\Kalender\Terminkalender;
use App\Models\CalendarEventLink;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Kalenderaufbau;

/*
|--------------------------------------------------------------------------
| WP-14, Abnahmekriterien 5, 8, 10, 13, 26 bis 29 -- was nach aussen geht
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

it('traegt in ein ausgehendes Event die Eigenmarkierung', function (): void {
    $aufbau = new Kalenderaufbau;

    app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    expect($aufbau->google->angelegt)->toHaveCount(1);

    $privat = $aufbau->google->angelegt[0]['extendedProperties']['private'];

    expect($privat[Eigenmarkierung::schluessel()])->toBe((string) $aufbau->organisation->uuid)
        ->and($privat)->toHaveKey(Eigenmarkierung::schluessel().'_appointment');
});

it('nennt im Titel weder Kontakt noch Behandlung', function (): void {
    $aufbau = new Kalenderaufbau;

    app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    $ereignis = $aufbau->google->angelegt[0];
    $text = json_encode($ereignis, JSON_UNESCAPED_UNICODE);

    expect($ereignis['summary'])->toBe('Beratung')
        ->and($text)->not->toContain($aufbau->szenario->kontakt->last_name)
        ->and($text)->not->toContain($aufbau->szenario->aufbau->art->name)
        // Und die belegte Strecke, nicht die angezeigte: der Behandler kann in
        // der Ruestzeit nichts anderes tun.
        ->and($ereignis['start']['timeZone'])->toBe($aufbau->szenario->aufbau->standort->timezone);
});

it('fuegt im Modus details einen Weg zum Inhalt hinzu, keinen Inhalt', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->verbindung->privacy_mode = CalendarPrivacyMode::Details;
    $aufbau->verbindung->save();

    app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    $ereignis = $aufbau->google->angelegt[0];

    expect($ereignis['summary'])->toBe('Beratung')
        ->and($ereignis['description'])->toStartWith(config('app.url'))
        ->and($ereignis['description'])->not->toContain($aufbau->szenario->aufbau->art->name);
});

it('schreibt nicht im Anfragezyklus', function (): void {
    $aufbau = new Kalenderaufbau;

    Queue::fake();

    app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    Queue::assertPushed(KalenderEventSchreiben::class, 1);
    Http::assertNothingSent();
});

it('erzeugt bei zwei Laeufen desselben Auftrags ein Event, nicht zwei', function (): void {
    $aufbau = new Kalenderaufbau;

    $termin = app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    // Der zweite Lauf ist nach einem Deploy der Normalfall.
    app(KalenderEventSchreiben::class, [
        'termin' => (string) $termin->uuid,
        'verbindung' => (string) $aufbau->verbindung->uuid,
        'organisation' => (string) $aufbau->organisation->uuid,
    ])->handle(app(Kalenderdienste::class));

    expect($aufbau->google->angelegt)->toHaveCount(1)
        ->and($aufbau->google->aktualisiert)->toHaveCount(1)
        ->and(CalendarEventLink::query()->count())->toBe(1);
});

it('aktualisiert beim Verschieben dasselbe Event', function (): void {
    $aufbau = new Kalenderaufbau;
    $erster = $aufbau->szenario->vorschlag(0);
    $zweiter = $aufbau->szenario->vorschlag(4);

    $termin = app(Terminplaner::class)->buche($erster, $aufbau->szenario->kontakt, jetzt: $aufbau->szenario->jetzt());

    app(Terminplaner::class)->verschiebe($termin, $zweiter, jetzt: $aufbau->szenario->jetzt());

    expect($aufbau->google->angelegt)->toHaveCount(1)
        ->and($aufbau->google->aktualisiert)->toHaveCount(1)
        ->and($aufbau->google->aktualisiert[0]['id'])->toBe('extern-1')
        ->and($aufbau->google->aktualisiert[0]['daten']['start']['dateTime'])
        ->toContain($zweiter->blockedFrom->setTimezone($aufbau->szenario->aufbau->standort->timezone)->format('H:i'));
});

it('entfernt das Event beim Absagen', function (): void {
    $aufbau = new Kalenderaufbau;

    $termin = app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    app(Terminplaner::class)->sageAb($termin, CancellationReason::Practice, jetzt: $aufbau->szenario->jetzt());

    expect($aufbau->google->geloescht)->toBe(['extern-1'])
        ->and(CalendarEventLink::query()->firstOrFail()->removed_at)->not->toBeNull();
});

it('laesst einen extern geloeschten Termin bestehen und schreibt ihn neu', function (): void {
    // Testfall 20, zweite Haelfte. R3: der externe Kalender gewinnt bei
    // Blockern, das System gewinnt bei Terminen.
    $aufbau = new Kalenderaufbau;
    $erster = $aufbau->szenario->vorschlag(0);
    $zweiter = $aufbau->szenario->vorschlag(4);

    $termin = app(Terminplaner::class)->buche($erster, $aufbau->szenario->kontakt, jetzt: $aufbau->szenario->jetzt());

    // Jemand loescht den Eintrag im Google-Kalender.
    $aufbau->google->eventFehlt = true;

    app(Terminplaner::class)->verschiebe($termin, $zweiter, jetzt: $aufbau->szenario->jetzt());

    expect($termin->fresh()?->starts_at->toIso8601String())->toBe($zweiter->startsAt->toIso8601String())
        // Kein zweiter Termin, aber ein zweites Event.
        ->and($aufbau->google->angelegt)->toHaveCount(2)
        ->and(CalendarEventLink::query()->firstOrFail()->external_event_id)->toBe('extern-2');
});

it('nimmt beim Wechsel des Behandlers den Eintrag aus dem alten Kalender', function (): void {
    $aufbau = new Kalenderaufbau;

    $termin = app(Terminplaner::class)->buche(
        $aufbau->szenario->vorschlag(),
        $aufbau->szenario->kontakt,
        jetzt: $aufbau->szenario->jetzt(),
    );

    // Der Termin bleibt, die Verbindung verschwindet: der Eintrag im alten
    // Kalender blockierte dort sonst Zeit, die frei ist.
    Queue::fake();

    app(Terminkalender::class)->beiAbsage($termin);

    Queue::assertPushed(KalenderEventEntfernen::class, 1);
});
