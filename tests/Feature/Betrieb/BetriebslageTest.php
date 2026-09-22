<?php

declare(strict_types=1);

use App\Betrieb\Betriebslage;
use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\ClamAvPruefung;
use App\Datenschutz\KeineVirenpruefung;
use App\Datenschutz\Scanergebnis;
use App\Datenschutz\ScannerNichtErreichbar;
use App\Datenschutz\Scanverbindung;
use App\Datenschutz\Virenpruefung;
use App\Enums\AttachmentContext;
use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-33 -- Virenpruefung und Betriebslage
|--------------------------------------------------------------------------
|
| Regel 4: "Stille Ausfaelle sind der Normalfall und keine Ausnahme: jede
| Verbindung wird ueberwacht, ein Ausfall erzeugt einen Hinweis **im
| Produkt**, nicht nur im Log."
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Storage::fake(config('mrs.attachments.disk'));
});

/** Ein Pruefdienst, der antwortet, was der Test will. */
function pruefdienst(string $antwort, bool $erreichbar = true): Scanverbindung
{
    return new class($antwort, $erreichbar) implements Scanverbindung
    {
        public function __construct(private readonly string $antwort, private readonly bool $erreichbar) {}

        public function pruefe(string $inhalt): string
        {
            if (! $this->erreichbar) {
                throw new ScannerNichtErreichbar('unreachable');
            }

            return $this->antwort;
        }
    };
}

function anhangMit(Virenpruefung $pruefer): Attachment
{
    app()->instance(Virenpruefung::class, $pruefer);

    $kontakt = Contact::create(['first_name' => 'Anna', 'last_name' => 'Mueller']);

    return app(Anhangspeicher::class)->lege($kontakt, 'Inhalt', 'befund.txt', AttachmentContext::Document);
}

/* Virenpruefung ------------------------------------------------------------ */

it('gibt ohne angebundenen Pruefer nichts frei', function (): void {
    // **Die Luecke, die WP-33 geschlossen hat.** Bis dahin schrieb die
    // Vorgabe `clean`, obwohl niemand hingesehen hatte.
    alsMandant(organisation('Demo-Praxis'));

    $anhang = anhangMit(new KeineVirenpruefung);

    expect($anhang->scan_result)->toBe(Scanergebnis::Unscanned->value)
        ->and($anhang->istFreigegeben())->toBeFalse();
});

it('gibt eine saubere Datei frei', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $anhang = anhangMit(new ClamAvPruefung(pruefdienst('stream: OK')));

    expect($anhang->scan_result)->toBe(Scanergebnis::Clean->value)
        ->and($anhang->istFreigegeben())->toBeTrue();
});

it('sperrt eine befallene Datei und haelt den Fund fest', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $anhang = anhangMit(new ClamAvPruefung(pruefdienst('stream: Eicar-Test-Signature FOUND')));

    expect($anhang->scan_result)->toStartWith('infected')
        ->and($anhang->scan_result)->toContain('Eicar-Test-Signature')
        ->and($anhang->istFreigegeben())->toBeFalse();
});

it('gibt nichts frei, wenn der Pruefer nicht antwortet', function (): void {
    // Ein Dienst, der gerade neu startet, darf keine Datei freigeben.
    alsMandant(organisation('Demo-Praxis'));

    $anhang = anhangMit(new ClamAvPruefung(pruefdienst('', erreichbar: false)));

    expect($anhang->scan_result)->toBe(Scanergebnis::Unscanned->value)
        ->and($anhang->istFreigegeben())->toBeFalse();
});

it('gibt nichts frei, wenn der Pruefer etwas Unverstaendliches sagt', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $anhang = anhangMit(new ClamAvPruefung(pruefdienst('ERROR: could not lock')));

    expect($anhang->scan_result)->toBe(Scanergebnis::Unscanned->value);
});

/* Betriebslage ------------------------------------------------------------- */

it('zeigt eine gestoerte Verbindung auf dem Dashboard', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $aufbau = new WhatsAppAufbau($organisation);

    $aufbau->verbindung->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->has('betrieb.gestoerteKanaele', 1)
            ->where('betrieb.gestoerteKanaele.0.grund', 'token_invalid')
        );
});

it('zaehlt fehlgeschlagene Auftraege fuer die Betriebsuebersicht', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    expect(app(Betriebslage::class)->auffaellig())->toBeFalse();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Boom',
        'failed_at' => now(),
    ]);

    $lage = app(Betriebslage::class)->fuerInstallation();

    expect($lage['fehlgeschlageneAuftraege'])->toBe(1)
        ->and(app(Betriebslage::class)->auffaellig())->toBeTrue();
});

it('meldet der Befehl einen Grund zum Hinsehen ueber den Exit-Code', function (): void {
    // Eine Ueberwachung liest keine Tabelle, sie liest 0 oder 1.
    alsMandant(organisation('Demo-Praxis'));

    expect(Artisan::call('mrs:betrieb'))->toBe(0);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Boom',
        'failed_at' => now(),
    ]);

    expect(Artisan::call('mrs:betrieb'))->toBe(1);
});
