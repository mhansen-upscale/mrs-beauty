<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\NotificationKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\Treatment;
use App\Models\User;
use App\Notifications\Terminnachricht;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-36, Abnahmekriterien 1 bis 13 und 29 -- die Vorlagen der Praxis
|--------------------------------------------------------------------------
|
| **Die Praxis schreibt um den Termin herum, nicht den Termin.** Der Betreff
| nennt keine Behandlung (C17), ein Wert wird nie zu Markup, und eine Vorlage
| ist eine Ueberschreibung, keine Kopie (D15).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function inhaberinMitPraxis(string $name = 'Praxis am Markt'): User
{
    $praxis = alsMandant(organisation($name));
    bezahltesAbo();
    praxispostfach();

    return User::factory()->fuer($praxis, Role::Owner)->create();
}

/**
 * @param  array<string, string>  $felder
 * @return array<string, string>
 */
function vorlage(array $felder = []): array
{
    return [
        'subject' => 'Ihr Termin am {tag}',
        'greeting' => 'Hallo {vorname},',
        'intro' => 'wir freuen uns auf Sie.',
        'outro' => 'Bis bald!',
        'salutation' => 'Ihr Team von {praxis}',
        ...$felder,
    ];
}

/** Eine Terminmail an einen echten Termin der geltenden Praxis. */
function terminmail(NotificationKind $art = NotificationKind::Reminder): Terminnachricht
{
    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);

    $praxis = app(TenantContext::class)->current();
    assert($praxis instanceof Organization);

    return new Terminnachricht($termin, $art, $praxis->name);
}

/* Uebersicht --------------------------------------------------------------- */

it('listet jede Mail mit Versandweg und wer sie gestaltet (AK 1)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->get(route('mailvorlagen.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/Mails')
            ->has('vorlagen', 5)
            ->where('vorlagen.0.art', 'eingangsbestaetigung')
            ->where('postfach.bereit', true)
            ->has('weitere', 9)
            ->where('weitere.0.weg', 'Postfach der Praxis'));
});

it('laesst ohne Faehigkeit niemanden an die Mails (AK 2)', function (): void {
    $inhaberin = inhaberinMitPraxis();
    $empfang = User::factory()->fuer(Organization::query()->firstOrFail(), Role::Reception)->create();

    actingAs($empfang)->get(route('mailvorlagen.index'))->assertForbidden();
    actingAs($empfang)->get(route('mailvorlagen.edit', ['mailart' => 'erinnerung']))->assertForbidden();
    actingAs($empfang)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage())->assertForbidden();

    expect($inhaberin->exists)->toBeTrue();
});

it('kennt unter der Praxis keine Mail der Plattform (AK 3)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->get('/settings/mails/anmeldecode')->assertNotFound();
    actingAs($inhaberin)->put('/settings/mails/einladung', vorlage())->assertNotFound();
});

/* Texte -------------------------------------------------------------------- */

it('legt je Praxis und Art genau eine Zeile an und aendert sie beim zweiten Mal (AK 4)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage())->assertSessionHasNoErrors();
    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['outro' => 'Bis Dienstag.']))->assertSessionHasNoErrors();

    expect(MailTemplate::query()->count())->toBe(1)
        ->and(MailTemplate::query()->firstOrFail()->outro)->toBe('Bis Dienstag.');
});

it('lehnt einen unbekannten Platzhalter am Feld ab und nennt die erlaubten (AK 5)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['intro' => 'Ihr {geburtstag} naht.']))
        ->assertSessionHasErrors(['intro' => 'Nicht erlaubt: {geburtstag}. Erlaubt sind {vorname}, {nachname}, {name}, {praxis}, {tag}, {datum}, {uhrzeit}, {standort}, {behandler}, {behandlung}.']);

    expect(MailTemplate::query()->count())->toBe(0);
});

it('nimmt im Betreff keine Behandlung, keine Person und keine Katalogbezeichnung an (AK 6)', function (string $betreff): void {
    $inhaberin = inhaberinMitPraxis();
    Treatment::factory()->create(['name' => 'Hyaluron-Unterspritzung', 'is_active' => true]);

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['subject' => $betreff]))
        ->assertSessionHasErrors('subject');

    expect(MailTemplate::query()->count())->toBe(0);
})->with([
    'Platzhalter Behandlung' => ['Ihre {behandlung} am {tag}'],
    'Platzhalter Behandlerin' => ['Termin bei {behandler}'],
    'Platzhalter Name' => ['{name}, Ihr Termin'],
    'Katalogbezeichnung' => ['Ihre Hyaluron-Unterspritzung am {tag}'],
    'Katalog in anderer Schreibung' => ['hyaluron-unterspritzung am {tag}'],
]);

it('faellt beim Erzeugen auf den Standardbetreff zurueck, wenn der Katalog gewachsen ist (AK 7)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['subject' => 'Ihr Microneedling am {tag}']))
        ->assertSessionHasNoErrors();

    // Nach dem Speichern kommt die Behandlung in den Katalog.
    Treatment::factory()->create(['name' => 'Microneedling', 'is_active' => true]);

    $betreff = (string) terminmail()->toMail(new AnonymousNotifiable)->subject;

    expect($betreff)->toStartWith('Ihr Termin am ')
        ->and($betreff)->not->toContain('Microneedling');
});

it('laesst Absaetze, fett und https-Links zu, sonst nichts (AK 8)', function (string $text, bool $erlaubt): void {
    $inhaberin = inhaberinMitPraxis();

    $antwort = actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['intro' => $text]));

    $erlaubt ? $antwort->assertSessionHasNoErrors() : $antwort->assertSessionHasErrors('intro');
})->with([
    'Absaetze' => ["Erster Absatz.\n\nZweiter Absatz.", true],
    'fett' => ['Bitte **pünktlich** sein.', true],
    'https-Link' => ['[Anfahrt](https://praxis.test/anfahrt)', true],
    'HTML' => ['<b>fett</b>', false],
    'Skript' => ['<script>alert(1)</script>', false],
    'Bild' => ['![Logo](https://praxis.test/logo.png)', false],
    'Ueberschrift' => ['# Wichtig', false],
    'http-Link' => ['[Anfahrt](http://praxis.test/anfahrt)', false],
    'nackte http-Adresse' => ['Siehe http://praxis.test', false],
    'javascript' => ['[klick](javascript:alert(1))', false],
    'Code' => ['`rm -rf`', false],
]);

it('macht aus einem Wert nie Markup (AK 9)', function (): void {
    inhaberinMitPraxis();

    $szenario = new Szenario;
    $szenario->kontakt->first_name = '**x** [a](https://evil.test)';
    $szenario->kontakt->save();

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);

    MailTemplate::query()->create([
        'template' => 'erinnerung',
        ...vorlage(['intro' => 'Liebe {vorname}, bis bald.']),
    ]);

    $html = (string) (new Terminnachricht($termin, NotificationKind::Reminder, 'Praxis'))
        ->toMail(new AnonymousNotifiable)
        ->render();

    expect($html)->not->toContain('href="https://evil.test"')
        ->and($html)->not->toContain('<strong>x</strong>')
        ->and($html)->toContain('[a](https://evil.test)');
});

it('stellt beim Zuruecksetzen den Standard wieder her (AK 10)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['intro' => 'Ganz eigener Text.']));
    actingAs($inhaberin)->delete(route('mailvorlagen.destroy', ['mailart' => 'erinnerung']))->assertSessionHasNoErrors();

    expect(MailTemplate::query()->count())->toBe(0)
        ->and(implode(' ', terminmail()->toMail(new AnonymousNotifiable)->introLines))
        ->toContain('wir möchten Sie an Ihren Termin erinnern.');
});

it('prueft den Text auf HWG, ohne das Speichern zu verhindern (AK 11)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'terminbestaetigung']), vorlage(['intro' => 'Garantiert faltenfrei.']))
        ->assertSessionHasNoErrors();

    $vorlage = MailTemplate::query()->with('pruefung')->firstOrFail();

    expect($vorlage->intro)->toBe('Garantiert faltenfrei.')
        ->and($vorlage->pruefung?->result->value)->toBe('red');

    actingAs($inhaberin)->get(route('mailvorlagen.edit', ['mailart' => 'terminbestaetigung']))
        ->assertInertia(fn ($seite) => $seite->where('hwg.ampel', 'red'));
});

it('laesst die Vorlage einer Praxis nie fuer eine andere gelten (AK 12)', function (): void {
    $inhaberin = inhaberinMitPraxis('Erste Praxis');
    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['intro' => 'Nur bei der ersten.']));

    alsMandant(organisation('Zweite Praxis'));

    expect(MailTemplate::query()->count())->toBe(0)
        ->and(implode(' ', terminmail()->toMail(new AnonymousNotifiable)->introLines))
        ->not->toContain('Nur bei der ersten.');
});

it('schreibt ohne Vorlage woertlich die bisherigen Texte (AK 13)', function (): void {
    inhaberinMitPraxis();

    $bisher = [
        'request_received' => ['Ihr Termin am ', 'vielen Dank für Ihre Anfrage. Wir haben sie erhalten und melden uns, sobald der Termin bestätigt ist.'],
        'confirmation' => ['Ihr Termin am ', 'Ihr Termin ist bestätigt.'],
        'reminder' => ['Ihr Termin am ', 'wir möchten Sie an Ihren Termin erinnern.'],
        'rescheduled' => ['Neuer Termin am ', 'Ihr Termin wurde verschoben. Er findet jetzt zu dieser Zeit statt:'],
        'cancellation' => ['Ihr Termin am ', 'Ihr Termin wurde abgesagt. Es handelt sich um diesen Termin:'],
    ];

    foreach ($bisher as $art => [$betreff, $einleitung]) {
        $nachricht = terminmail(NotificationKind::from($art))->toMail(new AnonymousNotifiable);

        expect((string) $nachricht->subject)->toStartWith($betreff)
            ->and($nachricht->introLines[0] ?? null)->toBe($einleitung)
            ->and($nachricht->greeting)->toBe('Guten Tag,')
            ->and($nachricht->salutation)->toBe('Viele Grüße, Praxis am Markt');
    }
});

/* Protokoll ---------------------------------------------------------------- */

it('protokolliert Speichern und Zuruecksetzen mit Mailart, ohne Text (AK 29)', function (): void {
    $inhaberin = inhaberinMitPraxis();

    actingAs($inhaberin)->put(route('mailvorlagen.update', ['mailart' => 'erinnerung']), vorlage(['intro' => 'Geheimnisvoller Satz.']));
    actingAs($inhaberin)->delete(route('mailvorlagen.destroy', ['mailart' => 'erinnerung']));

    $eintraege = AuditLog::query()->where('subject_type', (new MailTemplate)->getMorphClass())->get();

    expect($eintraege->pluck('event')->map(fn (AuditEvent $ereignis): string => $ereignis->value)->all())
        ->toContain(AuditEvent::Created->value)
        ->toContain(AuditEvent::Deleted->value);

    $roh = (string) json_encode($eintraege->map(fn (AuditLog $eintrag): array => [$eintrag->changed_fields, $eintrag->context])->all());

    expect($roh)->not->toContain('Geheimnisvoller Satz')
        ->and($roh)->toContain('erinnerung');
});
