<?php

declare(strict_types=1);

use App\Enums\MessageCostCategory;
use App\Enums\TemplateStatus;
use App\Kanaele\WhatsApp\Templateabgleich;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-20a, Abnahmekriterien 26 und 27 -- Templates
|--------------------------------------------------------------------------
|
| Genehmigt wird bei Meta, gelesen wird hier. Ein Template im Produkt
| anzulegen, das dort nicht genehmigt ist, hiesse eine Nachricht anzubieten,
| die beim Absenden abgelehnt wird -- und zwar erst dann.
|
*/

/**
 * @param  list<array<string, mixed>>  $templates
 */
function templateantwort(array $templates): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => $templates])]);
}

/**
 * Zwei Antworten nacheinander -- als **eine** Attrappe.
 *
 * Ein zweites Http::fake() auf dasselbe Muster ersetzt das erste nicht: die
 * erste passende Attrappe gewinnt. Wer das uebersieht, prueft zweimal
 * dieselbe Antwort und haelt den Test fuer gruen.
 *
 * @param  list<array<string, mixed>>  $erst
 * @param  list<array<string, mixed>>  $dann
 */
function templatefolge(array $erst, array $dann): void
{
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['data' => $erst])
        ->push(['data' => $dann]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function metaTemplate(string $name = 'terminerinnerung', string $status = 'APPROVED', string $kategorie = 'UTILITY'): array
{
    return [
        'id' => '123',
        'name' => $name,
        'language' => 'de',
        'status' => $status,
        'category' => $kategorie,
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Ihr Termin'],
            ['type' => 'BODY', 'text' => 'Guten Tag {{1}}, Ihr Termin am {{2}} steht. Bis {{2}}!'],
            ['type' => 'FOOTER', 'text' => 'Demo-Praxis'],
        ],
    ];
}

it('legt genehmigte Templates an und liest ihre Angaben', function (): void {
    $aufbau = new WhatsAppAufbau;
    templateantwort([metaTemplate()]);

    $ergebnis = app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    $template = WhatsAppTemplate::query()->firstOrFail();

    expect($ergebnis)->toBe(['angelegt' => 1, 'geaendert' => 0])
        ->and($template->name)->toBe('terminerinnerung')
        ->and($template->language)->toBe('de')
        ->and($template->status)->toBe(TemplateStatus::Approved)
        ->and($template->category)->toBe(MessageCostCategory::Utility)
        ->and($template->body)->toBe('Guten Tag {{1}}, Ihr Termin am {{2}} steht. Bis {{2}}!')
        // Gezaehlt wird die hoechste Nummer, nicht die Zahl der Treffer:
        // {{2}} kommt zweimal vor und ist trotzdem eine Variable.
        ->and($template->variables)->toBe(2);
});

it('liest unter der WABA-Kennung, nicht unter der Rufnummer', function (): void {
    // Templates gehoeren zum Konto, nicht zur Nummer.
    $aufbau = new WhatsAppAufbau;
    templateantwort([metaTemplate()]);

    app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    Http::assertSent(fn (Request $anfrage): bool => str_contains($anfrage->url(), WhatsAppAufbau::WABA.'/message_templates')
        && ! str_contains($anfrage->url(), WhatsAppAufbau::RUFNUMMER));
});

it('erzeugt beim zweiten Abgleich keine Dubletten und nimmt Abgelehntes zurueck', function (): void {
    $aufbau = new WhatsAppAufbau;
    templatefolge([metaTemplate()], [metaTemplate(status: 'REJECTED')]);

    app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    $ergebnis = app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    expect($ergebnis)->toBe(['angelegt' => 0, 'geaendert' => 1])
        ->and(WhatsAppTemplate::query()->count())->toBe(1)
        ->and(WhatsAppTemplate::query()->firstOrFail()->status)->toBe(TemplateStatus::Rejected)
        ->and(WhatsAppTemplate::query()->sendbar()->count())->toBe(0);
});

it('haelt denselben Namen in zwei Sprachen auseinander', function (): void {
    // **Genehmigt wird je Sprache**, nicht je Name: derselbe Text kann auf
    // Deutsch stehen und auf Englisch abgelehnt sein.
    $aufbau = new WhatsAppAufbau;

    $englisch = metaTemplate(status: 'PENDING');
    $englisch['language'] = 'en';

    templateantwort([metaTemplate(), $englisch]);

    app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    expect(WhatsAppTemplate::query()->count())->toBe(2)
        ->and(WhatsAppTemplate::query()->sendbar()->count())->toBe(1);
});

it('raet die Kategorie nicht, wenn Meta sie nicht nennt', function (): void {
    $aufbau = new WhatsAppAufbau;

    $ohne = metaTemplate();
    unset($ohne['category']);

    templateantwort([$ohne]);

    app(Templateabgleich::class)->gleicheAb($aufbau->verbindung);

    // Service ist die Kategorie ohne Aufpreis -- und die einzige, die eine
    // Nachricht im Fenster ueberhaupt haben kann.
    expect(WhatsAppTemplate::query()->firstOrFail()->category)->toBe(MessageCostCategory::Service);
});
