<?php

declare(strict_types=1);

use App\Compliance\Befund;
use App\Compliance\Pruefergebnis;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Pruefung;
use App\Enums\Ampel;
use App\Enums\BrandTermKind;
use App\Enums\ComplianceCode;
use App\Enums\Role;
use App\Models\BrandTerm;
use App\Models\ComplianceCheck;
use App\Models\ComplianceRuleset;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-30 -- HWG-Compliance-Engine
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-30-hwg-compliance.md.
|
| **Der Testsatz ist nicht juristisch geprueft.** Er bildet ab, was die
| Regeln tun sollen, nicht was ein Medizinrechtler bestaetigt hat -- das
| Abnahmekriterium dazu bleibt offen, und das Produkt sagt es an jeder Ampel.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
});

function pruefe(string $text, bool $mitBild = false): Pruefergebnis
{
    return app(Pruefung::class)->pruefe(new Pruefgegenstand(text: $text, hatBild: $mitBild));
}

/**
 * @return list<string>
 */
function codes(Pruefergebnis $ergebnis): array
{
    return array_map(fn (Befund $b): string => $b->code->value, $ergebnis->befunde);
}

/*
|--------------------------------------------------------------------------
| Der Testsatz
|--------------------------------------------------------------------------
*/

it('lehnt eine Botox-Anzeige mit Vorher-Nachher-Bild ab, nicht nur eine OP-Anzeige', function (): void {
    // **Der Kernfall**: das Bildverbot gilt seit BGH I ZR 170/24 vom
    // 31.07.2025 auch fuer minimalinvasive Eingriffe.
    alsMandant(organisation('Demo-Praxis'));

    $botox = pruefe('Botox gegen Zornesfalten — sehen Sie unsere Vorher-Nachher-Bilder.');
    $operation = pruefe('Lidstraffung — vorher und nachher im Vergleich.');

    expect($botox->ampel)->toBe(Ampel::Rot)
        ->and(codes($botox))->toContain(ComplianceCode::BeforeAfter->value)
        // Die Regel haengt nicht an der Art des Eingriffs.
        ->and($operation->ampel)->toBe(Ampel::Rot);
});

it('gibt ein Bild nie ohne menschliche Bestaetigung frei', function (): void {
    // Geteilte Bilder, Pfeile, Beschriftungen, Bildpaare -- nichts davon ist
    // automatisch zu erkennen. Gelb heisst: jemand muss hinsehen (Regel 6).
    alsMandant(organisation('Demo-Praxis'));

    $ohneBild = pruefe('Unsere Räume in Hamburg-Eppendorf.');
    $mitBild = pruefe('Unsere Räume in Hamburg-Eppendorf.', mitBild: true);

    expect($ohneBild->ampel)->toBe(Ampel::Gruen)
        ->and($mitBild->ampel)->toBe(Ampel::Gelb)
        ->and(codes($mitBild))->toContain(ComplianceCode::BeforeAfter->value);
});

it('erkennt Erfolgsversprechen', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    foreach (['Garantiert faltenfrei.', 'Wir garantieren ein sichtbares Ergebnis.', 'Wirkt immer.'] as $text) {
        $ergebnis = pruefe($text);

        // toContain nimmt kein Meldungsargument -- ein zweiter Wert waere
        // ein zweiter Erwartungswert.
        expect(codes($ergebnis))->toContain(ComplianceCode::HealingPromise->value)
            ->and($ergebnis->ampel)->toBe(Ampel::Rot);
    }
});

it('erkennt Aussagen ueber Risikofreiheit', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    foreach (['Schmerzfrei und ohne Ausfallzeit.', 'Völlig risikolos.', 'Ohne Nebenwirkungen.'] as $text) {
        expect(codes(pruefe($text)))->toContain(ComplianceCode::RiskFreeClaims->value);
    }
});

it('erkennt Werbung mit Empfehlungen', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    expect(codes(pruefe('Das sagen unsere Patientinnen über uns.')))
        ->toContain(ComplianceCode::Testimonial->value);
});

it('erkennt Angstwerbung und Superlative als gelb', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $angst = pruefe('Bevor es zu spät ist: jetzt Termin sichern.');
    $superlativ = pruefe('Die beste Praxis der Stadt.');

    expect(codes($angst))->toContain(ComplianceCode::FearAdvertising->value)
        ->and($angst->ampel)->toBe(Ampel::Gelb)
        ->and(codes($superlativ))->toContain(ComplianceCode::Superlatives->value)
        ->and($superlativ->ampel)->toBe(Ampel::Gelb);
});

it('vermisst den Pflichthinweis bei einem Eingriff und findet ihn, wenn er dasteht', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $ohne = pruefe('Unterspritzung mit Hyaluron in unserer Praxis.');
    $mit = pruefe('Unterspritzung mit Hyaluron. Wie bei jedem Eingriff gibt es Risiken — wir besprechen sie vorab.');

    expect(codes($ohne))->toContain(ComplianceCode::MissingRiskNotice->value)
        ->and(codes($mit))->not->toContain(ComplianceCode::MissingRiskNotice->value);
});

it('beanstandet einen zulaessigen Text nicht', function (): void {
    // **Die Gegenprobe.** Eine Pruefung, die alles rot faerbt, wird
    // abgeschaltet -- und dann greift auch die nicht mehr, die stimmt.
    alsMandant(organisation('Demo-Praxis'));

    $ergebnis = pruefe(
        'Dr. med. Martina Sauer berät Sie in Hamburg-Eppendorf. Im ersten Gespräch klären wir, '
        .'was möglich ist und was nicht. Wie bei jedem Eingriff gibt es Risiken; wir besprechen sie vorab.'
    );

    expect($ergebnis->ampel)->toBe(Ampel::Gruen)
        ->and($ergebnis->befunde)->toBeEmpty();
});

it('achtet auf Wortgrenzen', function (): void {
    // Ohne sie traefe "beste" jedes "Bestellung" und "OP" jedes "Optik".
    alsMandant(organisation('Demo-Praxis'));

    expect(codes(pruefe('Ihre Bestellung ist unterwegs.')))->not->toContain(ComplianceCode::Superlatives->value);
});

it('reicht die verbotenen Begriffe der Praxis als Befund durch', function (): void {
    // Dieselbe Liste, zwei Pruefungen -- so steht es im Briefing zu WP-29.
    alsMandant(organisation('Demo-Praxis'));

    BrandTerm::query()->create([
        'kind' => BrandTermKind::Verboten->value,
        'term' => 'billig',
        'replacement' => 'fair',
        'reason' => 'Positionierung',
    ]);

    $ergebnis = pruefe('Jetzt billig zur Beratung.');

    expect(codes($ergebnis))->toContain(ComplianceCode::BrandViolation->value)
        ->and($ergebnis->ampel)->toBe(Ampel::Gelb)
        ->and($ergebnis->befunde[0]->vorschlag)->toContain('fair');
});

/*
|--------------------------------------------------------------------------
| Regelwerk und Nachvollziehbarkeit
|--------------------------------------------------------------------------
*/

it('haelt Rechtsstand, Fassung und Pruefdatum an jedem Ergebnis fest', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $ergebnis = pruefe('Ein harmloser Satz.');

    expect($ergebnis->version)->toBe(1)
        ->and($ergebnis->rechtsstand->toDateString())->toBe('2025-07-31')
        ->and($ergebnis->geprueftAm->toDateString())->toBe('2026-09-17');
});

it('bleibt nach einer Regelwerksaenderung mit der urspruenglichen Fassung nachvollziehbar', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $behandlung = Treatment::factory()->create(['name' => 'Faltenbehandlung']);

    $pruefung = app(Pruefung::class)->pruefeUndHalteFest(
        $behandlung,
        new Pruefgegenstand(text: 'Garantiert faltenfrei.'),
    );

    expect($pruefung->ruleset_version)->toBe(1)
        ->and($pruefung->result)->toBe(Ampel::Rot);

    // Eine neue Fassung tritt in Kraft.
    ComplianceRuleset::query()->where('version', 1)->update(['valid_until' => '2026-09-16']);

    ComplianceRuleset::query()->create([
        'version' => 2,
        'legal_as_of' => '2026-09-17',
        'valid_from' => '2026-09-17',
        'changelog' => 'Zweite Fassung.',
    ]);

    // Das alte Ergebnis bleibt, wie es war -- samt der Fassung, gegen die es
    // entstanden ist.
    $frisch = ComplianceCheck::query()->firstOrFail();

    expect($frisch->ruleset_version)->toBe(1)
        ->and($frisch->legal_as_of->toDateString())->toBe('2025-07-31')
        // Eine neue Pruefung laeuft gegen die neue Fassung.
        ->and(pruefe('Ein Satz.')->version)->toBe(2);
});

it('prueft ohne Regelwerk gar nicht', function (): void {
    // Dieselbe Richtung wie bei der Virenpruefung in WP-33: was niemand
    // geprueft hat, wird nicht weitergereicht.
    alsMandant(organisation('Demo-Praxis'));

    ComplianceRuleset::query()->delete();

    expect(fn () => pruefe('Irgendetwas'))->toThrow(RuntimeException::class);
});

it('sagt, dass das Regelwerk juristisch ungeprueft ist', function (): void {
    // **Eine Ampel, der jemand vertraut, ohne dass sie geprueft ist, ist
    // gefaehrlicher als gar keine.**
    $organisation = alsMandant(organisation('Demo-Praxis'));

    expect(ComplianceRuleset::geltend()?->juristischGeprueft())->toBeFalse()
        ->and(pruefe('Ein Satz.')->regelwerkGeprueft)->toBeFalse();

    actingAs(User::factory()->fuer($organisation, Role::Owner)->create())
        ->get(route('hwg.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('regelwerk.geprueft', false));
});

/*
|--------------------------------------------------------------------------
| Uebersteuerung
|--------------------------------------------------------------------------
*/

it('gibt ein rotes Ergebnis nicht frei', function (): void {
    alsMandant(organisation('Demo-Praxis'));
    $behandlung = Treatment::factory()->create();

    $pruefung = app(Pruefung::class)->pruefeUndHalteFest(
        $behandlung,
        new Pruefgegenstand(text: 'Garantiert faltenfrei.'),
    );

    expect($pruefung->gibtFrei())->toBeFalse();
});

it('gibt auch Gelb nicht frei', function (): void {
    // Gelb heisst "jemand muss hinsehen" -- das ist nicht dasselbe wie
    // hingesehen haben.
    alsMandant(organisation('Demo-Praxis'));
    $behandlung = Treatment::factory()->create();

    $pruefung = app(Pruefung::class)->pruefeUndHalteFest(
        $behandlung,
        new Pruefgegenstand(text: 'Die beste Praxis der Stadt.'),
    );

    expect($pruefung->result)->toBe(Ampel::Gelb)
        ->and($pruefung->gibtFrei())->toBeFalse();
});

it('gibt nach einer begruendeten Uebersteuerung frei', function (): void {
    // Entscheidung C3: Override moeglich, mit Begruendung und Protokoll --
    // das Produkt ist eine Pruefhilfe, keine Rechtsberatung.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandlung = Treatment::factory()->create();

    $pruefung = app(Pruefung::class)->pruefeUndHalteFest(
        $behandlung,
        new Pruefgegenstand(text: 'Die beste Praxis der Stadt.'),
    );

    $pruefung->override_reason = 'Spitzenstellung durch Auszeichnung belegt, Nachweis liegt vor.';
    $pruefung->overridden_by_user_id = $inhaberin->getKey();
    $pruefung->overridden_at = CarbonImmutable::now();
    $pruefung->save();

    expect($pruefung->fresh()?->gibtFrei())->toBeTrue()
        ->and($pruefung->fresh()?->override_reason)->toContain('Auszeichnung');
});

/*
|--------------------------------------------------------------------------
| Regeln
|--------------------------------------------------------------------------
*/

it('legt die Befunde verschluesselt ab', function (): void {
    alsMandant(organisation('Demo-Praxis'));
    $behandlung = Treatment::factory()->create();

    app(Pruefung::class)->pruefeUndHalteFest($behandlung, new Pruefgegenstand(text: 'Garantiert faltenfrei.'));

    // Die geprueften Texte tragen Behandlungsbezeichnungen und haengen an
    // einem Mandanten.
    expect(DB::table('compliance_checks')->value('findings'))
        ->not->toContain('faltenfrei');
});

it('zeigt keiner Praxis die Pruefungen einer anderen', function (): void {
    alsMandant(organisation('Praxis A'));
    $behandlung = Treatment::factory()->create();
    app(Pruefung::class)->pruefeUndHalteFest($behandlung, new Pruefgegenstand(text: 'Garantiert faltenfrei.'));

    alsMandant(organisation('Praxis B'));

    expect(ComplianceCheck::query()->count())->toBe(0);
});

it('haelt das Regelwerk fuer alle Mandanten gleich', function (): void {
    // Entscheidung C1: global und versioniert. Eine mandantenbezogene Kopie
    // hiesse, dass ein Kunde mit veraltetem Regelwerk weiterarbeitet.
    alsMandant(organisation('Praxis A'));
    $ersteFassung = ComplianceRuleset::geltend()?->version;

    alsMandant(organisation('Praxis B'));

    expect(ComplianceRuleset::geltend()?->version)->toBe($ersteFassung)
        ->and(ComplianceRuleset::query()->count())->toBe(1);
});

it('beanstandet keinen Katalogeintrag ohne Beschreibung', function (): void {
    // Ein Eintrag ohne Text traegt keine Aussage. Ihn zu beanstanden, weil
    // sein Name eine Behandlung nennt, waere Laerm -- und Laerm schaltet eine
    // Pruefung ab.
    $organisation = alsMandant(organisation('Demo-Praxis'));

    Treatment::factory()->create(['name' => 'Lidstraffung', 'description' => null, 'is_active' => true]);
    Treatment::factory()->create([
        'name' => 'Hyaluron',
        'description' => 'Unterspritzung mit Hyaluron, garantiert faltenfrei.',
        'is_active' => true,
    ]);

    actingAs(User::factory()->fuer($organisation, Role::Owner)->create())
        ->get(route('hwg.index'))
        ->assertInertia(fn ($seite) => $seite
            // Nur der Eintrag mit Text steht in der Liste.
            ->has('katalog', 1)
            ->where('katalog.0.name', 'Hyaluron')
            ->where('katalog.0.ampel', 'red')
        );
});

it('laesst niemanden ohne brandguide.manage an die Pruefung', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(User::factory()->fuer($organisation, Role::Reception)->create())
        ->get(route('hwg.index'))
        ->assertForbidden();
});
