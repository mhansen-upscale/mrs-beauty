<?php

declare(strict_types=1);

use App\Kontakte\Kontaktsuche;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 29 bis 33 -- Kontakte
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

/** Liest die Rohbytes an Eloquent vorbei. */
function kontaktRohwert(string $spalte, string $id): ?string
{
    $zeile = DB::selectOne("SELECT `{$spalte}` AS wert FROM contacts WHERE id = ?", [$id]);

    return $zeile?->wert;
}

it('legt Name, E-Mail und Telefon verschluesselt ab', function (): void {
    $kontakt = Contact::create([
        'first_name' => 'Annika',
        'last_name' => 'Mueller',
        'email' => 'annika@example.test',
        'phone' => '+49 170 1234567',
    ]);

    foreach (['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test', 'phone' => '+49 170 1234567'] as $spalte => $klartext) {
        expect(kontaktRohwert($spalte, $kontakt->getKey()))->not->toContain($klartext);
    }

    expect($kontakt->fresh()?->name())->toBe('Annika Mueller');
});

it('findet einen Kontakt ueber die exakte E-Mail', function (): void {
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test']);

    expect(app(Kontaktsuche::class)->suche('annika@example.test'))->toHaveCount(1);
});

it('findet ihn unabhaengig von Gross- und Kleinschreibung', function (): void {
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test']);

    // BlindIndex::normalize() kleinschreibt und schneidet -- beim Schreiben
    // und beim Suchen dieselbe Normalisierung, sonst traefe es nie.
    expect(app(Kontaktsuche::class)->suche('  Annika@Example.Test '))->toHaveCount(1);
});

it('findet ihn ueber den exakten Nachnamen', function (): void {
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller']);
    Contact::create(['first_name' => 'Bernd', 'last_name' => 'Mueller']);
    Contact::create(['first_name' => 'Clara', 'last_name' => 'Schmidt']);

    expect(app(Kontaktsuche::class)->suche('mueller'))->toHaveCount(2);
});

it('findet ihn ueber eine Teilzeichenkette nicht', function (): void {
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller']);

    // Kein LIKE ueber ein verschluesseltes Feld (Entscheidung P8). Das ist
    // keine Nachlaessigkeit, sondern der Preis der Verschluesselung -- und es
    // gehoert als Hinweis in die Oberflaeche.
    expect(app(Kontaktsuche::class)->suche('Muel'))->toHaveCount(0);
});

it('findet keinen Kontakt einer fremden Organisation', function (): void {
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test']);

    alsMandant(organisation('Andere Praxis'));

    // Zwei Gruende, und beide greifen: der globale Scope, und der eigene
    // Indexschluessel je Organisation -- derselbe Klartext ergibt in einer
    // anderen Praxis einen anderen HMAC.
    expect(app(Kontaktsuche::class)->suche('annika@example.test'))->toHaveCount(0);
});

it('legt denselben Menschen nicht zweimal an', function (): void {
    $suche = app(Kontaktsuche::class);

    $erst = $suche->findeOderLege([
        'first_name' => 'Annika',
        'last_name' => 'Mueller',
        'email' => 'annika@example.test',
    ]);

    // Entscheidung D6: automatisches Zusammenfuehren nur bei identischer
    // E-Mail. Dieselbe Regel gilt beim Anlegen.
    $zweit = $suche->findeOderLege([
        'first_name' => 'Annika',
        'last_name' => 'Mueller-Schmidt',
        'email' => 'ANNIKA@example.test',
    ]);

    expect($zweit->getKey())->toBe($erst->getKey())
        ->and(Contact::query()->count())->toBe(1);
});

it('legt ohne E-Mail einen neuen Kontakt an', function (): void {
    $suche = app(Kontaktsuche::class);

    $suche->findeOderLege(['first_name' => 'Annika', 'last_name' => 'Mueller']);
    $suche->findeOderLege(['first_name' => 'Annika', 'last_name' => 'Mueller']);

    // Ohne Kontaktweg gibt es kein sicheres Merkmal fuer "dieselbe Person".
    // Lieber zwei Zeilen als zwei Menschen in einer.
    expect(Contact::query()->count())->toBe(2);
});
