<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| WP-16, Abnahmekriterien 5 bis 9 -- Kanalidentitäten (D5, D10)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('fuehrt zwei Kanaele einer Person auf einen Kontakt', function (): void {
    $kontakt = Contact::create(['first_name' => 'Anna', 'last_name' => 'Falk']);

    $kontakt->channelIdentities()->create([
        'channel' => ChannelType::Instagram,
        'external_id' => 'ig-4711',
    ]);
    $kontakt->channelIdentities()->create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '+49 170 1234567',
    ]);

    expect($kontakt->channelIdentities()->count())->toBe(2)
        ->and(Contact::query()->count())->toBe(1);
});

it('laesst eine Identitaet ohne Kontakt entstehen', function (): void {
    // Die erste Nachricht kommt an, bevor jemand weiss, wer da schreibt. Ein
    // erzwungener Bezug erzeugte hier Karteileichen -- oder falsche Kontakte.
    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::Messenger,
        'external_id' => 'psid-99',
    ]);

    expect($identitaet->contact_id)->toBeNull()
        ->and(ChannelIdentity::query()->count())->toBe(1);
});

it('laesst dieselbe Kennung auf demselben Kanal nur einmal zu', function (): void {
    ChannelIdentity::create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-4711']);

    expect(fn () => ChannelIdentity::create([
        'channel' => ChannelType::Instagram,
        'external_id' => 'ig-4711',
    ]))->toThrow(QueryException::class);
});

it('haelt dieselbe Kennung auf zwei Kanaelen auseinander', function (): void {
    // Meta vergibt Nutzerkennungen je Seite unterschiedlich -- gleiche
    // Zeichenkette heisst nicht gleiche Person.
    ChannelIdentity::create(['channel' => ChannelType::Instagram, 'external_id' => 'gleich']);
    ChannelIdentity::create(['channel' => ChannelType::Messenger, 'external_id' => 'gleich']);

    expect(ChannelIdentity::query()->count())->toBe(2);
});

it('haelt dieselbe Kennung in zwei Organisationen auseinander', function (): void {
    // Entscheidung D10: die Organisationsgrenze ist die Grenze des
    // Verantwortlichen nach DSGVO.
    ChannelIdentity::create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-4711']);

    $zweite = alsMandant(organisation('Zweite Praxis'));
    ChannelIdentity::create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-4711']);

    expect(ChannelIdentity::query()->count())->toBe(1);

    $gesamt = app(TenantContext::class)->acrossTenants(
        'Test prueft die Mandantengrenze',
        fn (): int => ChannelIdentity::query()->count(),
    );

    expect($gesamt)->toBe(2)
        ->and($zweite->name)->toBe('Zweite Praxis');
});

it('findet eine verschluesselte Kennung ueber den exakten Wert', function (): void {
    $kontakt = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);

    $kontakt->channelIdentities()->create([
        'channel' => ChannelType::Instagram,
        'external_id' => 'ig-4711',
    ]);

    $treffer = ChannelIdentity::query()->mitKennung(ChannelType::Instagram, 'ig-4711')->first();

    expect($treffer?->contact_id)->toBe($kontakt->getKey())
        ->and(ChannelIdentity::query()->mitKennung(ChannelType::Instagram, 'ig-999')->first())->toBeNull();
});

it('bringt eine Rufnummer als Kennung auf die kanonische Form', function (): void {
    $kontakt = Contact::create(['first_name' => 'Cem', 'last_name' => 'Yildiz']);

    $kontakt->channelIdentities()->create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '+49 170 1234567',
    ]);

    // Dieselbe Nummer, andere Schreibweise -- dieselbe Identitaet.
    $treffer = ChannelIdentity::query()->mitKennung(ChannelType::WhatsApp, '0170 1234567')->first();

    expect($treffer)->not->toBeNull()
        ->and($treffer?->kennungAnzeige())->toBe('+49 170 1234567');
});

it('haelt die Kennung im Klartext nirgends in der Datenbank', function (): void {
    ChannelIdentity::create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-geheim-4711']);

    $treffer = [];

    foreach (DB::select('show tables') as $zeile) {
        $tabelle = (string) array_values((array) $zeile)[0];

        foreach (DB::table($tabelle)->get() as $datensatz) {
            foreach ((array) $datensatz as $spalte => $wert) {
                if (is_string($wert) && str_contains($wert, 'ig-geheim')) {
                    $treffer[] = "{$tabelle}.{$spalte}";
                }
            }
        }
    }

    expect($treffer)->toBeEmpty('Die Kennung steht im Klartext: '.implode(', ', $treffer));
});
