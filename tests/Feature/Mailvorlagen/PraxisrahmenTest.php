<?php

declare(strict_types=1);

use App\Benachrichtigung\Mailmarke;
use App\Benachrichtigung\Termindaten;
use App\Enums\Mailart;
use App\Enums\NotificationKind;
use App\Models\Branding;
use App\Notifications\Mailprobe;
use App\Notifications\Terminnachricht;
use App\Support\Markenstil;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;

/*
|--------------------------------------------------------------------------
| WP-36, Abnahmekriterien 14 bis 16 -- das Aussehen der Praxis in der Mail
|--------------------------------------------------------------------------
|
| Die Farbe kommt ueber das Theme `mrs` in die Mail und steht danach
| **inline** -- Mailprogramme lesen kein <style> verlaesslich. Sie ist
| abgedunkelt wie auf der Buchungsseite, damit Weiss darauf lesbar bleibt.
|
*/

function terminmailHtml(Mailmarke $marke): string
{
    $praxis = app(TenantContext::class)->current() ?? alsMandant();

    return (string) (new Terminnachricht(Termindaten::beispiel($praxis), NotificationKind::Confirmation, $marke->name, $marke))
        ->toMail(new AnonymousNotifiable)
        ->render();
}

it('setzt die Markenfarbe inline auf Akzent und Links, abgedunkelt wie auf der Buchungsseite (AK 14)', function (): void {
    $praxis = alsMandant(organisation('Praxis Gold'));

    $bild = new Branding;
    $bild->primary_color = '#C9A227';
    $bild->save();

    $marke = Mailmarke::fuer($praxis);
    $erwartet = Markenstil::hexFuer('#C9A227');

    expect($marke->farbe)->toBe($erwartet)
        // Gold waere mit weisser Schrift nicht lesbar -- die Mail bekommt
        // dieselbe dunklere Stufe wie die Schaltflaeche der Buchungsseite.
        ->and($erwartet)->not->toBe('#C9A227');

    $html = terminmailHtml(new Mailmarke('Praxis Gold', farbe: $erwartet, signatur: []));

    expect($html)->toContain('border-top: 4px solid '.$erwartet);
});

it('nimmt ohne Markenfarbe die Produktfarbe', function (): void {
    alsMandant();

    $html = terminmailHtml(new Mailmarke('Praxis ohne Farbe'));

    expect($html)->toContain('border-top: 4px solid '.config('mrs.mail.produktfarbe'));
});

it('setzt die Farbe auch auf die Schaltflaeche, wo es eine gibt', function (): void {
    alsMandant();

    $html = (string) (new Mailprobe(Mailart::EmailBestaetigen))
        ->toMail(new AnonymousNotifiable)
        ->render();

    expect($html)->toContain('class="button button-primary"')
        ->and($html)->toMatch('/class="button button-primary"[^>]*style="[^"]*background-color: '.preg_quote((string) config('mrs.mail.produktfarbe'), '/').'/');
});

it('setzt die Signatur unter den Gruss, Logo und Rechtslinks bleiben (AK 15)', function (): void {
    alsMandant(organisation('Praxis Sonnenschein'));

    $html = terminmailHtml(new Mailmarke(
        'Praxis Sonnenschein',
        logo: 'https://mrs-beauty.test/buchen/sonnenschein/logo',
        impressum: 'https://praxis-sonnenschein.test/impressum',
        datenschutz: 'https://praxis-sonnenschein.test/datenschutz',
        signatur: ['Praxis Sonnenschein', 'Hauptstraße 1, 20095 Hamburg', 'Telefon 040 123456'],
    ));

    $gruss = mb_strpos($html, 'Viele Grüße, Praxis Sonnenschein');
    $signatur = mb_strpos($html, 'Hauptstraße 1, 20095 Hamburg');

    expect($gruss)->toBeInt()
        ->and($signatur)->toBeGreaterThan((int) $gruss)
        ->and($html)->toContain('Telefon 040 123456')
        ->and($html)->toContain('src="https://mrs-beauty.test/buchen/sonnenschein/logo"')
        ->and($html)->toContain('https://praxis-sonnenschein.test/impressum');
});

it('laesst keine Farbe in die Mail, die kein #RRGGBB ist (AK 16)', function (string $farbe): void {
    alsMandant();

    $marke = new Mailmarke('Praxis', farbe: $farbe);
    $html = terminmailHtml($marke);

    expect($marke->farbe)->toBeNull()
        ->and($html)->not->toContain('expression(')
        ->and($html)->not->toContain('url(javascript')
        ->and($html)->toContain('border-top: 4px solid '.config('mrs.mail.produktfarbe'));
})->with([
    'CSS-Ausbruch' => ['red;} body { background: url(javascript:alert(1)) }'],
    'Name' => ['red'],
    'Ausdruck' => ['expression(alert(1))'],
    'zu kurz' => ['#123'],
]);

it('maskiert die Signatur wie jeden Wert', function (): void {
    alsMandant();

    $html = terminmailHtml(new Mailmarke('Praxis', signatur: ['[klick](https://evil.test)', '<script>alert(1)</script>']));

    expect($html)->not->toContain('href="https://evil.test"')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});
