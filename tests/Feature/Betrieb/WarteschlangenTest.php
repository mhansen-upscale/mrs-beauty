<?php

declare(strict_types=1);

use App\Betrieb\Betriebslage;
use App\Betrieb\Warteschlangen;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| WP-33 -- der Ersatz fuer den Supervisor-Test
|--------------------------------------------------------------------------
|
| Solange Horizon die Arbeiter startete, hielt ein Test fest, dass jede
| Warteschlange in jeder Umgebung einen Supervisor hat: die Zusage stand in
| config/horizon.php und war damit pruefbar.
|
| Auf Laravel Cloud liegt die Arbeiterdefinition in der Oberflaeche des
| Anbieters. **Kein Test in diesem Repository kann noch pruefen, ob sie
| existiert.** Genau daran ist am 22.09.2026 die Bilderzeugung gescheitert:
| der Auftrag wurde angenommen, landete auf der Cloud-Warteschlange und blieb
| dort liegen -- kein Fehler, keine Meldung, nichts im Protokoll.
|
| Was bleibt, ist die Beobachtung zur Laufzeit: **liegt etwas, und hat seit
| einer Frist niemand etwas abgeholt, steht die Warteschlange.** Das ist
| Regel 4 -- ein Hinweis im Produkt, nicht nur im Log.
|
| Ein Vermerk allein ist kein Alarm: eine leere Warteschlange, die seit Tagen
| nichts zu tun hatte, ist in Ordnung. Erst Tiefe **und** Stille zusammen
| sind eine Stoerung.
|
*/

beforeEach(function (): void {
    Queue::fake();
});

function warteschlangen(): Warteschlangen
{
    return app(Warteschlangen::class);
}

it('meldet nichts, wenn keine Auftraege liegen', function (): void {
    // Seit Tagen kein Lauf -- aber auch nichts zu tun.
    expect(warteschlangen()->stehende())->toBeEmpty();
});

it('meldet Stillstand, wenn Auftraege liegen und nie einer abgeholt wurde', function (): void {
    Queue::push('irgendwas', '', 'maintenance');

    expect(warteschlangen()->stehende())->toContain('maintenance');
});

it('meldet nichts, solange gerade gearbeitet wurde', function (): void {
    $jetzt = CarbonImmutable::now();

    Queue::push('irgendwas', '', 'maintenance');
    warteschlangen()->vermerkeLauf('maintenance', $jetzt->subMinutes(5));

    expect(warteschlangen()->stehende($jetzt))->toBeEmpty();
});

it('meldet Stillstand, wenn der letzte Lauf zu lange her ist', function (): void {
    $jetzt = CarbonImmutable::now();

    Queue::push('irgendwas', '', 'maintenance');
    warteschlangen()->vermerkeLauf('maintenance', $jetzt->subMinutes(90));

    expect(warteschlangen()->stehende($jetzt))->toContain('maintenance');
});

/**
 * **Die Fristen sind nicht gleich**, weil die Warteschlangen es nicht sind.
 *
 * Derselbe Rueckstand ist fuer `realtime` eine unbeantwortete Nachricht und
 * fuer `maintenance` ein normaler Dienstagvormittag.
 */
it('nimmt die Frist der jeweiligen Warteschlange', function (): void {
    $jetzt = CarbonImmutable::now();
    $vorZehnMinuten = $jetzt->subMinutes(10);

    Queue::push('irgendwas', '', 'realtime');
    Queue::push('irgendwas', '', 'maintenance');

    warteschlangen()->vermerkeLauf('realtime', $vorZehnMinuten);
    warteschlangen()->vermerkeLauf('maintenance', $vorZehnMinuten);

    $stehende = warteschlangen()->stehende($jetzt);

    expect($stehende)->toContain('realtime')
        ->and($stehende)->not->toContain('maintenance');
});

/**
 * **Der Name kommt vom Treiber, und jeder schreibt ihn anders.**
 *
 * Redis meldet `queues:maintenance`, SQS eine vollstaendige URL, die
 * Datenbank den blanken Namen. Wer hier nur den Rohwert vermerkt, vergleicht
 * ihn spaeter mit einem Namen, den es nie gibt -- und meldet fuer immer
 * Stillstand.
 */
it('erkennt den Namen der Warteschlange bei jedem Treiber', function (string $roh): void {
    $jetzt = CarbonImmutable::now();

    Queue::push('irgendwas', '', 'maintenance');

    // Ein echter Auftrag statt eines Mocks -- aber **nicht** SyncJob: dessen
    // getQueue() liefert fest 'sync' und haette diesen Test gruen gemacht,
    // ohne je den Rohnamen zu pruefen.
    $auftrag = new class(app(), 'cloud', $roh) extends Job implements JobContract
    {
        public function __construct(Container $behaelter, string $verbindung, string $warteschlange)
        {
            $this->container = $behaelter;
            $this->connectionName = $verbindung;
            $this->queue = $warteschlange;
        }

        public function attempts(): int
        {
            return 1;
        }

        public function getJobId(): string
        {
            return 'egal';
        }

        public function getRawBody(): string
        {
            return (string) json_encode(['job' => 'irgendwas', 'data' => []]);
        }
    };

    event(new JobProcessed('cloud', $auftrag));

    expect(warteschlangen()->stehende($jetzt))->toBeEmpty();
})->with([
    'blank (database)' => 'maintenance',
    'redis' => 'queues:maintenance',
    'sqs' => 'https://sqs.eu-central-1.amazonaws.com/123456789012/maintenance',
]);

it('zaehlt stehende Warteschlangen zur Betriebslage', function (): void {
    Queue::push('irgendwas', '', 'maintenance');

    $lage = app(Betriebslage::class);

    expect($lage->fuerInstallation()['stehendeWarteschlangen'])->toContain('maintenance')
        ->and($lage->auffaellig())->toBeTrue();
});
