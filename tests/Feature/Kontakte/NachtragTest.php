<?php

declare(strict_types=1);

use App\Kontakte\Kontaktsuche;
use App\Models\Contact;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ein neuer blinder Index macht bestehende Daten unauffindbar
|--------------------------------------------------------------------------
|
| Die Migration legt die Spalte an; gefuellt wird sie vom saving-Haken, und
| der laeuft fuer eine Zeile, die niemand mehr anfasst, nie. Das faellt nicht
| auf -- kein Fehler, keine Meldung, nur eine Suche, die nichts findet.
|
| Genau das ist in WP-16 passiert, als phone_bidx dazukam: die Demodaten
| waren vorher angelegt und ueber die Telefonnummer nicht mehr zu finden.
|
*/

beforeEach(function (): void {
    alsMandant();
});

/** Eine Zeile, wie sie vor der Migration entstanden waere. */
function ohneIndex(Contact $kontakt): void
{
    DB::table('contacts')->where('id', $kontakt->getKey())->update(['phone_bidx' => null]);
}

it('findet eine Zeile ohne nachgetragenen Index nicht', function (): void {
    $kontakt = Contact::create(['first_name' => 'Alt', 'last_name' => 'Bestand', 'phone' => '+49 170 1234567']);
    ohneIndex($kontakt);

    expect(app(Kontaktsuche::class)->suche('0170 1234567'))->toBeEmpty();
});

it('traegt den Index nach und macht die Zeile wieder auffindbar', function (): void {
    $kontakt = Contact::create(['first_name' => 'Alt', 'last_name' => 'Bestand', 'phone' => '+49 170 1234567']);
    ohneIndex($kontakt);

    expect(Artisan::call('mrs:blindindex-nachtragen'))->toBe(0)
        ->and(app(Kontaktsuche::class)->suche('0170 1234567'))->toHaveCount(1);
});

it('aendert im Trockenlauf nichts', function (): void {
    $kontakt = Contact::create(['first_name' => 'Alt', 'last_name' => 'Bestand', 'phone' => '+49 170 1234567']);
    ohneIndex($kontakt);

    expect(Artisan::call('mrs:blindindex-nachtragen', ['--trocken' => true]))->toBe(0)
        ->and(DB::table('contacts')->where('id', $kontakt->getKey())->value('phone_bidx'))->toBeNull();
});

it('laesst sich zweimal laufen, ohne etwas zu tun', function (): void {
    Contact::create(['first_name' => 'Neu', 'last_name' => 'Angelegt', 'phone' => '+49 170 1234567']);

    Artisan::call('mrs:blindindex-nachtragen');
    Artisan::call('mrs:blindindex-nachtragen');

    expect(Artisan::output())->toContain('0 Zeilen nachgetragen');
});

it('laesst einen Kontakt ohne Nummer in Ruhe', function (): void {
    $kontakt = Contact::create(['first_name' => 'Ohne', 'last_name' => 'Nummer']);

    Artisan::call('mrs:blindindex-nachtragen');

    expect(DB::table('contacts')->where('id', $kontakt->getKey())->value('phone_bidx'))->toBeNull();
});
