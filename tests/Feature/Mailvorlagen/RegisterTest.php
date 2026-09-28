<?php

declare(strict_types=1);

use App\Benachrichtigung\Termindaten;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Benachrichtigung\Versand\PraxisMailkanal;
use App\Benachrichtigung\Vorlagen\Standardtexte;
use App\Benachrichtigung\Vorlagen\Textpruefung;
use App\Contracts\Plattformmail;
use App\Contracts\Praxismail;
use App\Enums\Mailart;
use App\Enums\Mailfeld;
use App\Enums\NotificationKind;
use App\Enums\Versandweg;
use App\Notifications\Mailprobe;
use App\Notifications\Terminnachricht;
use Illuminate\Notifications\AnonymousNotifiable;

/*
|--------------------------------------------------------------------------
| Das Register der Mails (WP-36, WP-37; Entscheidungen A15, D15)
|--------------------------------------------------------------------------
|
| **Jede Mail hat genau einen Versandweg**, und er gehoert zur Mail, nicht
| zum Aufrufer. Eine Notification ohne Weg gibt es nicht: sonst ginge sie
| still ueber den Standardmailer -- unter wessen Namen auch immer.
|
*/

/** @return list<string> */
function alleNotifications(): array
{
    return array_map(
        fn (string $datei): string => 'App\\Notifications\\'.basename($datei, '.php'),
        glob(app_path('Notifications/*.php')) ?: [],
    );
}

it('gibt jeder Notification genau einen Versandweg (A15)', function (): void {
    expect(alleNotifications())->not->toBeEmpty();

    foreach (alleNotifications() as $klasse) {
        $praxis = is_subclass_of($klasse, Praxismail::class);
        $plattform = is_subclass_of($klasse, Plattformmail::class);

        expect($praxis xor $plattform)->toBeTrue("{$klasse} braucht genau einen Versandweg.");
    }
});

it('schickt jede Mail ueber den Kanal ihres Wegs', function (): void {
    $praxis = alsMandant();

    $probe = new Mailprobe(Mailart::Anmeldecode);

    expect($probe->via(new AnonymousNotifiable))->toBe([PlattformMailkanal::class]);

    $termin = new Terminnachricht(Termindaten::beispiel($praxis), NotificationKind::Reminder, 'Praxis');

    expect($termin->via(new AnonymousNotifiable))->toBe([PraxisMailkanal::class]);
});

it('nimmt in keinem Kanal die Mail des anderen Wegs an (WP-37 AK 16)', function (): void {
    $praxis = alsMandant();

    $termin = new Terminnachricht(Termindaten::beispiel($praxis), NotificationKind::Reminder, 'Praxis');
    $empfaenger = (new AnonymousNotifiable)->route('mail', 'patientin@example.test');

    expect(fn () => app(PlattformMailkanal::class)->send($empfaenger, $termin))->toThrow(LogicException::class)
        ->and(fn () => app(PraxisMailkanal::class)->send($empfaenger, new Mailprobe(Mailart::Anmeldecode)))->toThrow(LogicException::class);
});

it('fuehrt jede Mailart genau einem Weg zu, die Terminmails der Praxis', function (): void {
    foreach (NotificationKind::cases() as $art) {
        expect(Mailart::fuerTermin($art)->versandweg())->toBe(Versandweg::Praxis)
            ->and(Mailart::fuerTermin($art)->istVorlage())->toBeTrue();
    }

    expect(Mailart::vorlagen(Versandweg::Praxis))->toHaveCount(5)
        ->and(Mailart::vorlagen(Versandweg::Plattform))->toHaveCount(6);
});

it('laesst die Standardtexte ihre eigene Pruefung bestehen (WP-36 AK 30)', function (): void {
    $pruefung = new Textpruefung;

    foreach (Mailart::cases() as $art) {
        if (! $art->istVorlage()) {
            continue;
        }

        expect($pruefung->pruefeAlle($art, Standardtexte::fuer($art)))->toBe([], "Der Standardtext von {$art->value} besteht die Pruefung nicht.");
    }
});

it('nennt in keinem Betreff einer Terminmail eine Behandlung oder Person (C17)', function (): void {
    foreach (Mailart::vorlagen(Versandweg::Praxis) as $art) {
        $erlaubt = array_map(fn ($platzhalter) => $platzhalter->value, $art->platzhalter(Mailfeld::Betreff));

        expect(array_intersect($erlaubt, ['behandlung', 'behandler', 'name', 'vorname', 'nachname']))->toBe([]);
    }

    // Die Code-Mails haben im Betreff gar keinen Platzhalter (WP-37 AK 4).
    expect(Mailart::Anmeldecode->platzhalter(Mailfeld::Betreff))->toBe([])
        ->and(Mailart::Einrichtungscode->platzhalter(Mailfeld::Betreff))->toBe([]);
});

it('kennt keinen Platzhalter fuer den Inhalt einer Nachricht (WP-37 AK 5)', function (): void {
    foreach (Mailfeld::cases() as $feld) {
        $namen = array_map(fn ($platzhalter) => $platzhalter->value, Mailart::Agentenalarm->platzhalter($feld));

        expect($namen)->each->toBeIn(['praxis', 'grund', 'produkt']);
    }
});

it('fuehrt die Demo-Anfrage als feste Plattformmail, die keine Praxis sieht (WP-38 AK 19)', function (): void {
    expect(Mailart::Demoanfrage->versandweg())->toBe(Versandweg::Plattform)
        ->and(Mailart::Demoanfrage->istVorlage())->toBeFalse()
        ->and(Mailart::Demoanfrage->anDenBetreiber())->toBeTrue()
        ->and(Mailart::Versandprobe->anDenBetreiber())->toBeTrue()
        ->and(Mailart::Agentenalarm->anDenBetreiber())->toBeFalse()
        ->and(Mailart::Demoanfrage->platzhalter(Mailfeld::Betreff))->toBe([]);

    // Die Zahlen oben bleiben: eine Mail an den Betreiber gestaltet niemand.
    expect(Mailart::vorlagen(Versandweg::Plattform))->not->toContain(Mailart::Demoanfrage);
});
