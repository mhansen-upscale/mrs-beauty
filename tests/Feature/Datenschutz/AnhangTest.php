<?php

declare(strict_types=1);

use App\Datenschutz\Anhangabgelehnt;
use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\Scanergebnis;
use App\Enums\AttachmentContext;
use App\Models\Attachment;
use App\Models\Contact;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-18 -- Anhänge (Entscheidung C6)
|--------------------------------------------------------------------------
|
| Eine Praxis für ästhetische Behandlungen bekommt täglich ungefragt
| zugesandte Fotos. Das sind Gesundheitsdaten nach Artikel 9 DSGVO, um die
| niemand gebeten hat — und deshalb tragen sie ein Pflicht-Ablaufdatum.
|
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Storage::fake('local');
});

it('nimmt einen Chat-Anhang ohne Ablaufdatum nicht an -- auf Datenbankebene', function (): void {
    // Nicht ueber das Modell, sondern daran vorbei: ein Seeder, eine
    // Migration oder ein vergessener Pfad kaeme genauso hier vorbei.
    $mandant = app(TenantContext::class)->requireId();

    expect(fn () => DB::table('attachments')->insert([
        'id' => Uuid::generate(),
        'organization_id' => $mandant,
        'attachable_type' => Contact::class,
        'attachable_id' => Uuid::generate(),
        'context' => 'chat',
        'original_name' => 'foto.jpg',
        'path' => 'anhaenge/x',
        'mime' => 'image/jpeg',
        'size_bytes' => 10,
        'checksum' => str_repeat("\x00", 32),
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('nimmt ein Dokument ohne Ablaufdatum an', function (): void {
    $kontakt = Contact::create(['first_name' => 'Anna', 'last_name' => 'Falk']);

    $anhang = app(Anhangspeicher::class)->lege(
        $kontakt,
        'Einwilligungsbogen',
        'einwilligung.pdf',
        AttachmentContext::Document,
    );

    expect($anhang->expires_at)->toBeNull();
});

it('setzt bei einem Chat-Anhang die Frist aus der Konfiguration', function (): void {
    $kontakt = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);

    $anhang = app(Anhangspeicher::class)->lege($kontakt, 'binaerfoto', 'foto.jpg', AttachmentContext::Chat);

    expect($anhang->expires_at?->toDateString())
        ->toBe(CarbonImmutable::now()->addDays(90)->toDateString());
});

it('legt die Datei verschluesselt ab', function (): void {
    $kontakt = Contact::create(['first_name' => 'Cem', 'last_name' => 'Yildiz']);

    $anhang = app(Anhangspeicher::class)->lege(
        $kontakt,
        'Der Befund lautet Rosenkohl.',
        'befund.txt',
        AttachmentContext::Document,
    );

    $roh = Storage::disk('local')->get($anhang->path);

    // Seit WP-33 gibt die Vorgabe nichts frei: ohne angebundenen Pruefer
    // steht `unscanned` am Anhang, und was niemand geprueft hat, wird nicht
    // ausgeliefert. Fuer diesen Test -- er prueft die Verschluesselung, nicht
    // die Freigabe -- steht das Ergebnis eines Pruefers da.
    $anhang->scan_result = Scanergebnis::Clean->value;
    $anhang->save();

    expect((string) $roh)->not->toContain('Rosenkohl')
        // Lesbar ist sie trotzdem -- fuer den, der den Schluessel hat.
        ->and(app(Anhangspeicher::class)->inhalt($anhang->fresh() ?? $anhang))
        ->toBe('Der Befund lautet Rosenkohl.');
});

it('haelt den Dateinamen verschluesselt', function (): void {
    // "befund-mueller.pdf" sagt mehr, als es soll.
    $kontakt = Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth']);

    app(Anhangspeicher::class)->lege($kontakt, 'inhalt', 'befund-rosenkohl.pdf', AttachmentContext::Document);

    /** @var object{original_name: string} $zeile */
    $zeile = DB::table('attachments')->first();

    expect($zeile->original_name)->not->toContain('rosenkohl');
});

it('erkennt den Typ aus dem Inhalt und nicht aus der Endung', function (): void {
    // Eine Endung ist eine Behauptung des Absenders -- bei ungefragt
    // zugesandten Dateien die unzuverlaessigste Angabe ueberhaupt.
    $kontakt = Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart']);

    $anhang = app(Anhangspeicher::class)->lege(
        $kontakt,
        '%PDF-1.4 ein Dokument',
        'bild.jpg',
        AttachmentContext::Document,
    );

    expect($anhang->mime)->toBe('application/pdf');
});

it('liefert einen ungeprueften Anhang nicht aus', function (): void {
    $kontakt = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $anhang = app(Anhangspeicher::class)->lege($kontakt, 'inhalt', 'datei.txt', AttachmentContext::Document);

    $anhang->scanned_at = null;
    $anhang->scan_result = null;
    $anhang->save();

    expect(fn () => app(Anhangspeicher::class)->inhalt($anhang))->toThrow(Anhangabgelehnt::class);
});

it('nimmt beim Loeschen die Datei mit', function (): void {
    // Nur die Zeile zu entfernen liesse das Foto auf dem Speicher liegen --
    // der Unterschied zwischen geloescht und nur unsichtbar.
    $kontakt = Contact::create(['first_name' => 'Gerd', 'last_name' => 'Halm']);
    $anhang = app(Anhangspeicher::class)->lege($kontakt, 'inhalt', 'datei.txt', AttachmentContext::Document);
    $pfad = $anhang->path;

    expect(Storage::disk('local')->exists($pfad))->toBeTrue();

    app(Anhangspeicher::class)->entferne($anhang);

    expect(Storage::disk('local')->exists($pfad))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);
});

/**
 * **Eine fehlende Datei ist kein leerer Anhang.**
 *
 * `rohinhalt()` gab eine leere Zeichenkette zurueck, wenn der Speicher
 * nichts lieferte. Auf der lokalen Platte war das selten; mit einem Bucket
 * ist es der Normalfall eines Fehlers -- falsche Region, fehlende
 * Berechtigung, Objekt geloescht. Die Anzeige streamte dann null Bytes, und
 * niemand erfuhr warum.
 */
it('meldet eine fehlende Datei, statt Leere auszugeben', function (): void {
    $kontakt = Contact::create(['first_name' => 'Clara', 'last_name' => 'Nord']);

    $anhang = app(Anhangspeicher::class)->lege(
        traeger: $kontakt,
        inhalt: 'bilddaten',
        dateiname: 'bild.png',
        kontext: AttachmentContext::Document,
    );

    Storage::disk((string) config('mrs.attachments.disk'))->delete($anhang->path);

    expect(fn (): string => app(Anhangspeicher::class)->rohinhalt($anhang))
        ->toThrow(Anhangabgelehnt::class, 'nicht mehr im Speicher');
});
