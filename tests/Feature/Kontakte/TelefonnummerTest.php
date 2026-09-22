<?php

declare(strict_types=1);

use App\Kontakte\Kontaktsuche;
use App\Models\Contact;
use App\Support\Telefonnummer;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| WP-16, Abnahmekriterien 1 bis 4 -- Telefonnummern
|--------------------------------------------------------------------------
|
| Die offene Rechnung aus WP-11: dort fehlte der blinde Index auf der
| Telefonnummer ausdruecklich, weil "+49 170 1234567" und "01701234567" ohne
| Normalisierung zwei Hashes ergeben -- und die Suche damit still nie trifft.
|
*/

beforeEach(function (): void {
    alsMandant();
});

it('bringt dieselbe Nummer in jeder Schreibweise auf denselben Index', function (): void {
    $schreibweisen = ['+49 170 1234567', '0170 1234567', '0049-170-1234567', '(0170) 123 45 67'];

    $indizes = [];

    foreach ($schreibweisen as $nummer) {
        $kontakt = Contact::create([
            'first_name' => 'Anke',
            'last_name' => 'Beispiel'.count($indizes),
            'phone' => $nummer,
        ]);

        /** @var object{phone_bidx: string} $zeile */
        $zeile = DB::table('contacts')->where('id', $kontakt->getKey())->first();
        $indizes[] = bin2hex($zeile->phone_bidx);
    }

    expect(array_unique($indizes))->toHaveCount(1);
});

it('speichert eine unleserliche Nummer, ohne sie zu indizieren', function (): void {
    // Eine unleserliche Nummer ist ein Kontaktweg, den jemand abtippen kann.
    // Sie zu verwerfen waere schlimmer, als sie nicht zu finden.
    $kontakt = Contact::create([
        'first_name' => 'Bernd',
        'last_name' => 'Krakel',
        'phone' => 'ruft immer mittwochs an',
    ]);

    /** @var object{phone_bidx: ?string} $zeile */
    $zeile = DB::table('contacts')->where('id', $kontakt->getKey())->first();

    expect($zeile->phone_bidx)->toBeNull()
        ->and($kontakt->fresh()?->phone)->toBe('ruft immer mittwochs an');
});

it('findet den Kontakt unabhaengig von der Schreibweise der Eingabe', function (): void {
    Contact::create([
        'first_name' => 'Claudia',
        'last_name' => 'Roth',
        'phone' => '+49 170 1234567',
    ]);

    foreach (['0170 1234567', '+491701234567', '0049 170 1234567'] as $eingabe) {
        expect(app(Kontaktsuche::class)->suche($eingabe))->toHaveCount(1, "Eingabe: {$eingabe}");
    }
});

it('findet ueber eine unleserliche Eingabe nichts statt irgendetwas', function (): void {
    Contact::create(['first_name' => 'Dora', 'last_name' => 'Klein', 'phone' => '+49 170 1234567']);

    // "12345" ist keine Nummer -- die Suche faellt auf den Nachnamen zurueck
    // und findet dort nichts. Sie darf nicht irgendetwas liefern.
    expect(app(Kontaktsuche::class)->suche('12345'))->toBeEmpty();
});

it('erkennt am Suchbegriff, welches Feld gemeint ist', function (): void {
    expect(Kontaktsuche::feldFuer('anna@praxis.test'))->toBe('email')
        ->and(Kontaktsuche::feldFuer('0170 1234567'))->toBe('phone')
        ->and(Kontaktsuche::feldFuer('Müller'))->toBe('last_name');
});

it('nimmt die Standardregion aus der Konfiguration', function (): void {
    expect(config('mrs.contacts.default_region'))->toBe('DE')
        ->and(Telefonnummer::e164('0170 1234567'))->toBe('+491701234567')
        // Mit einer anderen Region ist dieselbe Eingabe eine andere Nummer.
        ->and(Telefonnummer::e164('0170 1234567', 'AT'))->not->toBe('+491701234567');
});

it('haelt die Nummer im Klartext nirgends in der Datenbank', function (): void {
    Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart', 'phone' => '+49 170 7654321']);

    $treffer = [];

    foreach (DB::select('show tables') as $zeile) {
        $tabelle = (string) array_values((array) $zeile)[0];

        foreach (DB::table($tabelle)->get() as $datensatz) {
            foreach ((array) $datensatz as $spalte => $wert) {
                if (is_string($wert) && str_contains($wert, '7654321')) {
                    $treffer[] = "{$tabelle}.{$spalte}";
                }
            }
        }
    }

    expect($treffer)->toBeEmpty('Die Telefonnummer steht im Klartext: '.implode(', ', $treffer));
});
