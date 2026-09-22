<?php

declare(strict_types=1);

use App\Datenschutz\Einwilligungen;
use App\Enums\ChannelType;
use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Kontakte\Zusammenfuehrung;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-18 -- Einwilligungen (Entscheidungen D8 und D9)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function kanal(Contact $kontakt, string $kennung, ChannelType $typ = ChannelType::WhatsApp): ChannelIdentity
{
    /** @var ChannelIdentity */
    return $kontakt->channelIdentities()->create([
        'channel' => $typ,
        'external_id' => $kennung,
    ]);
}

it('haengt die Einwilligung an die Kanalidentitaet, nicht an die Person', function (): void {
    // Entscheidung D8: ein WhatsApp-Opt-in haengt an einer Rufnummer.
    $kontakt = Contact::create(['first_name' => 'Anna', 'last_name' => 'Falk']);
    $eine = kanal($kontakt, '+49 170 1111111');
    $andere = kanal($kontakt, '+49 170 2222222');

    app(Einwilligungen::class)->erteile($eine, ConsentType::WhatsApp, 'v1', 'Ich stimme zu.');

    expect(app(Einwilligungen::class)->darfSenden($eine, ConsentType::WhatsApp))->toBeTrue()
        ->and(app(Einwilligungen::class)->darfSenden($andere, ConsentType::WhatsApp))->toBeFalse();
});

it('trennt die Zwecke', function (): void {
    // Wer einer Terminerinnerung zustimmt, hat keiner Werbung zugestimmt.
    $kontakt = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);
    $identitaet = kanal($kontakt, '+49 170 1111111');

    app(Einwilligungen::class)->erteile($identitaet, ConsentType::ServiceMessages, 'v1', 'Nur Termine.');

    expect(app(Einwilligungen::class)->darfSenden($identitaet, ConsentType::ServiceMessages))->toBeTrue()
        ->and(app(Einwilligungen::class)->darfSenden($identitaet, ConsentType::Marketing))->toBeFalse();
});

it('laesst einen Widerruf sofort wirken, auch neben einer aelteren Erteilung', function (): void {
    $kontakt = Contact::create(['first_name' => 'Cem', 'last_name' => 'Yildiz']);
    $identitaet = kanal($kontakt, '+49 170 1111111');

    app(Einwilligungen::class)->erteile($identitaet, ConsentType::WhatsApp, 'v1', 'Ja.', jetzt: CarbonImmutable::now()->subDays(10));
    app(Einwilligungen::class)->widerrufe($identitaet, ConsentType::WhatsApp, 'v1', 'Nein.');

    expect(app(Einwilligungen::class)->darfSenden($identitaet, ConsentType::WhatsApp))->toBeFalse()
        // Die aeltere Erteilung bleibt als Nachweis stehen.
        ->and(DB::table('consents')->count())->toBe(2);
});

it('laesst bei gleichem Zeitpunkt den Widerruf gewinnen', function (): void {
    // Kein Sonderfall aus der Theorie: ein Formular, das beim Absenden eine
    // alte Zustimmung beendet und eine neue setzt, erzeugt ihn sekundengenau.
    $kontakt = Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth']);
    $identitaet = kanal($kontakt, '+49 170 1111111');

    $zeitpunkt = CarbonImmutable::now();

    app(Einwilligungen::class)->erteile($identitaet, ConsentType::Marketing, 'v1', 'Ja.', jetzt: $zeitpunkt);
    app(Einwilligungen::class)->widerrufe($identitaet, ConsentType::Marketing, 'v1', 'Nein.', jetzt: $zeitpunkt);

    expect(app(Einwilligungen::class)->stand($identitaet, ConsentType::Marketing)?->action)
        ->toBe(ConsentAction::Revoked);
});

it('bildet nach einer Zusammenfuehrung keine Vereinigung', function (): void {
    // Entscheidung D9: je Kanal die juengste Einwilligung. Nach dem
    // Zusammenfuehren haengen zwei Nummern am selben Kontakt -- an die
    // widerrufene darf trotzdem nicht geschrieben werden.
    $gewinner = Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart']);
    $verlierer = Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart']);

    $erlaubt = kanal($gewinner, '+49 170 1111111');
    $verboten = kanal($verlierer, '+49 170 2222222');

    app(Einwilligungen::class)->erteile($erlaubt, ConsentType::WhatsApp, 'v1', 'Ja.');
    app(Einwilligungen::class)->erteile($verboten, ConsentType::WhatsApp, 'v1', 'Ja.', jetzt: CarbonImmutable::now()->subDay());
    app(Einwilligungen::class)->widerrufe($verboten, ConsentType::WhatsApp, 'v1', 'Nein.');

    app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    $empfaenger = app(Einwilligungen::class)->empfaenger($gewinner->fresh() ?? $gewinner, ConsentType::WhatsApp);

    expect($gewinner->fresh()?->channelIdentities()->count())->toBe(2)
        ->and($empfaenger)->toHaveCount(1)
        ->and($empfaenger->first()?->getKey())->toBe($erlaubt->getKey());
});

it('haelt den Wortlaut der Erklaerung fest, nicht nur ihre Version', function (): void {
    // Die Erklaerung wird ueberarbeitet. Wer nur die Version speichert, kann
    // spaeter nicht mehr zeigen, wozu jemand zugestimmt hat -- und die
    // Beweislast liegt beim Verantwortlichen.
    $kontakt = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $identitaet = kanal($kontakt, '+49 170 1111111');

    $text = 'Ich bin einverstanden, Terminerinnerungen per WhatsApp zu erhalten.';
    $eintrag = app(Einwilligungen::class)->erteile($identitaet, ConsentType::WhatsApp, 'v3', $text);

    expect($eintrag->fresh()?->text_snapshot)->toBe($text)
        ->and($eintrag->text_version)->toBe('v3');
});

it('haelt Erklaerung und Umstaende verschluesselt', function (): void {
    $kontakt = Contact::create(['first_name' => 'Gerd', 'last_name' => 'Halm']);
    $identitaet = kanal($kontakt, '+49 170 1111111');

    app(Einwilligungen::class)->erteile(
        $identitaet,
        ConsentType::WhatsApp,
        'v1',
        'Einverstanden mit Kennwort Rosenkohl.',
        ip: '203.0.113.9',
        browser: 'Mozilla/5.0 Rosenkohl',
    );

    /** @var object{text_snapshot: string, ip_address: ?string} $zeile */
    $zeile = DB::table('consents')->first();

    expect($zeile->text_snapshot)->not->toContain('Rosenkohl')
        ->and((string) $zeile->ip_address)->not->toContain('203.0.113');
});
