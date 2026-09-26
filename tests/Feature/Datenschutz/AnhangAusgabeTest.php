<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\Scanergebnis;
use App\Enums\AttachmentContext;
use App\Enums\AuditEvent;
use App\Enums\BrandReferenceKind;
use App\Enums\ChannelType;
use App\Enums\Role;
use App\Kanaele\Konversationen;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BrandReference;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| Anhaenge ausliefern (offen seit WP-21)
|--------------------------------------------------------------------------
|
| Aufgelistet wurden sie seit WP-21, ausgeliefert nie -- "samt der Frage,
| wer sie sehen darf". Die Antwort steht hier:
|
| - **Woran der Anhang haengt, entscheidet, wer ihn sieht.** Ein Chat-Anhang
|   gehoert zum Posteingang, Referenzmaterial zum Brand Guide.
| - **Nur Geprueftes** (WP-18, WP-29): was niemand geprueft hat, geht nicht
|   hinaus.
| - **Nichts waehrend einer maskierten Impersonation** (C4): ein Foto
|   laesst sich nicht maskieren.
| - **Jedes Oeffnen eines Chat-Anhangs steht im Protokoll** -- es sind
|   Gesundheitsdaten nach Artikel 9 DSGVO.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Storage::fake('local');
});

/** Ein Foto aus dem Chat, mit dem Ergebnis der Pruefung. */
function chatfoto(Scanergebnis $ergebnis = Scanergebnis::Clean): Attachment
{
    $identitaet = ChannelIdentity::create(['channel' => ChannelType::WhatsApp, 'external_id' => '4915112345678']);
    $gespraech = app(Konversationen::class)->fuer($identitaet);

    $nachricht = app(Konversationen::class)->nimmAuf($gespraech, 'wamid.foto', '')
        ?? throw new RuntimeException('Keine Nachricht.');

    $anhang = app(Anhangspeicher::class)->lege($nachricht, (string) file_get_contents(base_path('tests/Fixtures/bild.png')), 'foto.png', AttachmentContext::Chat);
    $anhang->forceFill(['scanned_at' => CarbonImmutable::now(), 'scan_result' => $ergebnis->value])->save();

    return $anhang;
}

it('liefert einen geprueften Chat-Anhang an den Empfang aus', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    $anhang = chatfoto();

    $antwort = actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]));

    $antwort->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Ein Bild darf im Browser erscheinen -- aber in einer Sandbox, die
    // nichts ausfuehrt, und nie aus einem Zwischenspeicher.
    expect((string) $antwort->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((string) $antwort->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and((string) $antwort->headers->get('Cache-Control'))->toContain('no-store')
        ->and($antwort->streamedContent())->toBe((string) file_get_contents(base_path('tests/Fixtures/bild.png')));
});

it('bietet alles ausser Bildern zum Herunterladen an, nie zur Anzeige', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    $anhang = chatfoto();
    $anhang->forceFill(['mime' => 'text/html'])->save();

    $antwort = actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]));

    // Eine HTML-Datei aus dem Chat, im Browser geoeffnet, liefe unter
    // unserer Adresse. Also: herunterladen, als Bytes.
    expect((string) $antwort->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and((string) $antwort->headers->get('Content-Type'))->toBe('application/octet-stream');
});

it('liefert einen ungeprueften Chat-Anhang nicht aus', function (Scanergebnis $ergebnis): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    $anhang = chatfoto($ergebnis);

    actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertNotFound();
})->with([
    'ungeprueft' => [Scanergebnis::Unscanned],
    'beanstandet' => [Scanergebnis::Infected],
]);

it('zeigt Chat-Anhaenge nur, wer den Posteingang sieht', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $marketing = User::factory()->fuer($praxis, Role::Marketing)->create();
    $anhang = chatfoto();

    actingAs($marketing)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertForbidden();
});

it('haelt das Oeffnen eines Chat-Anhangs im Protokoll fest', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    $anhang = chatfoto();

    actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertOk();

    app(TenantContext::class)->set($praxis);

    expect(AuditLog::query()->where('event', AuditEvent::AttachmentOpened->value)->count())->toBe(1);
});

it('liefert waehrend einer maskierten Impersonation nichts aus', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $support = User::factory()->superAdmin()->create();
    $anhang = chatfoto();

    $sitzung = app(Impersonation::class)->start($support, $praxis, 'Ticket 4711, Foto kommt nicht an');

    // Ein Foto laesst sich nicht maskieren wie ein Name.
    actingAs($support)
        ->withSession(['impersonation_session_id' => $sitzung->uuid])
        ->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))
        ->assertForbidden();
});

it('findet den Anhang einer anderen Praxis nicht', function (): void {
    $andere = alsMandant(organisation('Andere Praxis'));
    $fremd = chatfoto();

    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $fremd->uuid]))->assertNotFound();
});

it('zeigt Referenzmaterial der Marke, wer den Brand Guide pflegt', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $marketing = User::factory()->fuer($praxis, Role::Marketing)->create();
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    $referenz = BrandReference::create([
        'kind' => BrandReferenceKind::cases()[0],
        'title' => 'Empfang',
        'declared_at' => CarbonImmutable::now(),
        'declaration_text' => 'Keine Patientenaufnahmen.',
    ]);

    $anhang = app(Anhangspeicher::class)->lege(
        $referenz,
        (string) file_get_contents(base_path('tests/Fixtures/bild.png')),
        'empfang.png',
        AttachmentContext::BrandReference,
    );

    // Auch eigenes Material erst nach der Pruefung (WP-29) -- anders als das
    // Logo, das seine eigene Route hat.
    actingAs($marketing)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertNotFound();

    $anhang->forceFill(['scanned_at' => CarbonImmutable::now(), 'scan_result' => Scanergebnis::Clean->value])->save();

    actingAs($marketing)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertOk();
    actingAs($empfang)->get(route('anhang.zeigen', ['attachment' => $anhang->uuid]))->assertForbidden();
});
