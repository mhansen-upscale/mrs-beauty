<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\Role;
use App\Models\ChannelConnection;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Terminnachricht;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| WP-36, Abnahmekriterien 17 bis 20 -- Vorschau und Probemail
|--------------------------------------------------------------------------
|
| **Die Vorschau verschickt nichts und speichert nichts.** Sie merkt sich den
| Entwurf in der Sitzung und zeigt ihn gerendert wie beim Versand. Die
| Probemail geht an die Person, die sie bestellt -- ueber das Postfach der
| Praxis, wie jede echte Terminmail.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function vorschauinhaberin(bool $mitPostfach = true): User
{
    $praxis = alsMandant(organisation('Praxis am Markt'));
    bezahltesAbo();

    if ($mitPostfach) {
        praxispostfach();
    }

    return User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'chefin@praxis-am-markt.test']);
}

/**
 * @param  array<string, string>  $felder
 * @return array<string, string>
 */
function entwurf(array $felder = []): array
{
    return [
        'subject' => 'Bald ist es so weit: {tag}',
        'greeting' => 'Hallo {vorname},',
        'intro' => 'wir freuen uns auf Ihren Besuch.',
        'outro' => '',
        'salutation' => 'Ihr Team',
        ...$felder,
    ];
}

it('zeigt den Entwurf gerendert, ohne zu verschicken und ohne zu speichern (AK 17)', function (): void {
    Notification::fake();
    $attrappe = postfachAttrappe();
    $inhaberin = vorschauinhaberin();

    actingAs($inhaberin)
        ->post(route('mailvorlagen.vorschau', ['mailart' => 'erinnerung']), entwurf())
        ->assertSessionHasNoErrors();

    actingAs($inhaberin)->get(route('mailvorlagen.edit', ['mailart' => 'erinnerung']))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/Mailvorlage')
            ->where('entwurf.greeting', 'Hallo {vorname},')
            ->where('vorschau.betreff', fn (string $betreff): bool => str_starts_with($betreff, 'Bald ist es so weit: '))
            ->where('vorschau.html', fn (string $html): bool => str_contains($html, 'Hallo Erika,') && str_contains($html, 'Praxis am Markt'))
            ->where('vorschau.text', fn (string $text): bool => str_contains($text, 'wir freuen uns auf Ihren Besuch.')));

    Notification::assertNothingSent();

    expect(versandteMails($attrappe))->toBeEmpty()
        ->and(MailTemplate::query()->count())->toBe(0);
});

it('prueft den Entwurf in der Vorschau wie beim Speichern (AK 18)', function (): void {
    $inhaberin = vorschauinhaberin();

    actingAs($inhaberin)
        ->post(route('mailvorlagen.vorschau', ['mailart' => 'erinnerung']), entwurf(['subject' => 'Ihre {behandlung}']))
        ->assertSessionHasErrors('subject');
});

it('zeigt jede Vorschau nur in einem abgeschotteten iframe (AK 19)', function (): void {
    $dateien = Finder::create()->files()->name('*.vue')->in(resource_path('js'));
    $gefunden = 0;

    foreach ($dateien as $datei) {
        preg_match_all('/<iframe\b[^>]*>/s', (string) file_get_contents($datei->getRealPath()), $treffer);

        foreach ($treffer[0] as $iframe) {
            $gefunden++;

            // **Leer, nicht erlaubt-sparsam**: kein Skript, kein Formular,
            // kein Zugriff auf die Seite drumherum.
            // toContain nimmt kein Meldungsargument -- ein zweiter Wert waere
            // ein zweiter Erwartungswert.
            expect(str_contains($iframe, 'sandbox=""'))->toBeTrue("{$datei->getRelativePathname()}: iframe ohne sandbox=\"\".");
        }
    }

    expect($gefunden)->toBeGreaterThan(0);
});

it('schickt die Probemail ueber die Warteschlange an die angemeldete Person (AK 20)', function (): void {
    Notification::fake();
    $inhaberin = vorschauinhaberin();

    actingAs($inhaberin)
        ->post(route('mailvorlagen.probe', ['mailart' => 'erinnerung']), entwurf())
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(Terminnachricht::class, function (Terminnachricht $nachricht, array $kanaele, AnonymousNotifiable $an): bool {
        $mail = $nachricht->toMail($an);

        return array_key_exists('chefin@praxis-am-markt.test', (array) $an->routes['mail'])
            && str_starts_with((string) $mail->subject, 'Probe: Bald ist es so weit: ');
    });
});

it('schickt ohne Postfach keine Probemail und sagt es an der Schaltflaeche (AK 20)', function (): void {
    Notification::fake();
    $inhaberin = vorschauinhaberin(mitPostfach: false);

    actingAs($inhaberin)
        ->post(route('mailvorlagen.probe', ['mailart' => 'erinnerung']), entwurf())
        ->assertSessionHasErrors('probe');

    Notification::assertNothingSent();
});

it('schickt waehrend einer Impersonation keine Probemail (AK 20)', function (): void {
    Notification::fake();
    $inhaberin = vorschauinhaberin();
    $praxis = Organization::query()->firstOrFail();
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Rueckfrage zu den Mails, Ticket 4711');
    ohneMandant();

    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
        ->post(route('mailvorlagen.probe', ['mailart' => 'erinnerung']), entwurf())
        ->assertForbidden();

    Notification::assertNothingSent();

    expect($inhaberin->exists)->toBeTrue()
        ->and(ChannelConnection::query()->withoutGlobalScopes()->count())->toBe(1);
});
