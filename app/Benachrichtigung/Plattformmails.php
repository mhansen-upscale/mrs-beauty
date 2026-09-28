<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Benachrichtigung\Vorlagen\Festblock;
use App\Benachrichtigung\Vorlagen\Mailinhalt;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Benachrichtigung\Vorlagen\Mailvorlagen;
use App\Benachrichtigung\Vorlagen\Textbaustein;
use App\Enums\Mailart;
use App\Enums\Platzhalter;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Die Produktmails an Konten -- aus Vorlage, festem Kern und Rahmen (WP-37).
 *
 * **Der Kern steht hier, nicht in der Vorlage** (C17): Schaltflaeche und Link,
 * die Frist, der Code, der Satz im Alarm. Eine Vorlage schreibt davor und
 * danach. So gibt es keinen Text, der einen Code in den Betreff zieht oder
 * einen Nachrichteninhalt in den Alarm.
 *
 * Gelesen wird zur Versandzeit, im Arbeiter ohne Mandant: die Vorlagen des
 * Betreibers sind global.
 */
final class Plattformmails
{
    public function __construct(private readonly Mailvorlagen $vorlagen) {}

    public function emailBestaetigen(string $name, string $url, int $minuten): MailMessage
    {
        return $this->baue(Mailart::EmailBestaetigen, null, [
            Platzhalter::Name->value => $name,
            Platzhalter::Minuten->value => (string) $minuten,
        ], new Festblock(
            schaltflaeche: 'E-Mail-Adresse bestätigen',
            ziel: $url,
            nachher: ["Der Link gilt {$minuten} Minuten."],
        ));
    }

    public function passwort(string $name, string $url, int $minuten): MailMessage
    {
        return $this->baue(Mailart::PasswortZuruecksetzen, null, [
            Platzhalter::Name->value => $name,
            Platzhalter::Minuten->value => (string) $minuten,
        ], new Festblock(
            schaltflaeche: 'Neues Passwort vergeben',
            ziel: $url,
            nachher: ["Der Link gilt {$minuten} Minuten."],
        ));
    }

    public function einladung(string $praxis, string $rolle, string $frist, string $url): MailMessage
    {
        return $this->baue(Mailart::Einladung, null, [
            Platzhalter::Praxis->value => $praxis,
            Platzhalter::Rolle->value => $rolle,
            Platzhalter::Frist->value => $frist,
        ], new Festblock(
            vorher: ['Ihre Rolle: '.Textbaustein::maskiere($rolle).'.'],
            schaltflaeche: 'Einladung annehmen',
            ziel: $url,
            nachher: ['Die Einladung gilt bis zum '.Textbaustein::maskiere($frist).' Uhr.'],
        ));
    }

    /**
     * **Ohne den Inhalt der Nachricht** (WP-23, C17). Kein Platzhalter traegt
     * ihn; der Satz, dass er fehlt, ist Kern.
     */
    public function agentenalarm(string $praxis, string $grund, string $url): MailMessage
    {
        return $this->baue(Mailart::Agentenalarm, null, [
            Platzhalter::Praxis->value => $praxis,
            Platzhalter::Grund->value => $grund,
        ], new Festblock(
            vorher: [
                'Der Assistent hat sie an Sie übergeben: '.Textbaustein::maskiere($grund).'.',
                'Der Inhalt steht **nicht** in dieser E-Mail — er gehört in kein Postfach und auf keinen Sperrbildschirm.',
            ],
            schaltflaeche: 'Im Posteingang öffnen',
            ziel: $url,
        ));
    }

    /**
     * **Der Code steht nur im Kern**, nie im Betreff: der erscheint auf dem
     * Sperrbildschirm (WP-35).
     */
    public function anmeldecode(#[\SensitiveParameter] string $code, int $minuten, bool $einrichtung, string $name): MailMessage
    {
        $art = $einrichtung ? Mailart::Einrichtungscode : Mailart::Anmeldecode;

        return $this->baue($art, null, [
            Platzhalter::Name->value => $name,
            Platzhalter::Minuten->value => (string) $minuten,
        ], new Festblock(vorher: [
            '**'.Textbaustein::maskiere($code).'**',
            "Der Code gilt {$minuten} Minuten und nur einmal.",
            $einrichtung
                ? 'Haben Sie das nicht angefordert? Dann kennt jemand Ihr Passwort — bitte ändern Sie es.'
                : 'Haben Sie sich nicht gerade angemeldet? Dann kennt jemand Ihr Passwort — bitte ändern Sie es.',
        ]));
    }

    /**
     * **Ohne eine Angabe der Anfrage** (WP-38). Eine Kopie in einem Postfach
     * erreicht keine Aufbewahrungsfrist, und ein Betreff mit Namen steht auf
     * einem Sperrbildschirm. Die Mail sagt, dass eine kam und wann -- gelesen
     * wird im Backoffice.
     *
     * Fest, keine Vorlage: sie geht an den Betreiber selbst.
     */
    public function demoanfrage(CarbonImmutable $eingang, string $url): MailMessage
    {
        $ortszeit = $eingang->setTimezone((string) config('mrs.business_timezone'))->format('d.m.Y, H:i');

        return Mailaufbau::baue(Mailmarke::fuerPlattform(), new Mailinhalt(
            betreff: Mailart::Demoanfrage->label(),
            anrede: 'Guten Tag,',
            einleitung: ['auf der Startseite hat jemand eine Demo angefragt.'],
            schluss: [],
            gruss: 'Viele Grüße von '.Textbaustein::maskiere((string) config('app.name')),
        ), new Festblock(
            vorher: [
                "Eingegangen am {$ortszeit} Uhr.",
                'Die Angaben stehen **nicht** in dieser E-Mail, sondern im Backoffice.',
            ],
            schaltflaeche: 'Im Backoffice öffnen',
            ziel: $url,
        ));
    }

    /**
     * Fuer Vorschau und Probemail: mit Beispielwerten, einem Entwurf oder der
     * geltenden Vorlage.
     */
    public function beispiel(Mailart $art, ?Mailtext $entwurf = null): MailMessage
    {
        $beispiel = fn (Platzhalter $platzhalter): string => $platzhalter->beispiel();
        $link = (string) config('app.url');

        return match ($art) {
            Mailart::EmailBestaetigen, Mailart::PasswortZuruecksetzen => $this->baue($art, $entwurf, [
                Platzhalter::Name->value => $beispiel(Platzhalter::Name),
                Platzhalter::Minuten->value => $beispiel(Platzhalter::Minuten),
            ], new Festblock(
                schaltflaeche: $art === Mailart::EmailBestaetigen ? 'E-Mail-Adresse bestätigen' : 'Neues Passwort vergeben',
                ziel: $link,
                nachher: ['Der Link gilt '.$beispiel(Platzhalter::Minuten).' Minuten.'],
            )),
            Mailart::Einladung => $this->baue($art, $entwurf, [
                Platzhalter::Praxis->value => $beispiel(Platzhalter::Praxis),
                Platzhalter::Rolle->value => $beispiel(Platzhalter::Rolle),
                Platzhalter::Frist->value => $beispiel(Platzhalter::Frist),
            ], new Festblock(
                vorher: ['Ihre Rolle: '.$beispiel(Platzhalter::Rolle).'.'],
                schaltflaeche: 'Einladung annehmen',
                ziel: $link,
                nachher: ['Die Einladung gilt bis zum '.$beispiel(Platzhalter::Frist).' Uhr.'],
            )),
            Mailart::Agentenalarm => $this->baue($art, $entwurf, [
                Platzhalter::Praxis->value => $beispiel(Platzhalter::Praxis),
                Platzhalter::Grund->value => $beispiel(Platzhalter::Grund),
            ], new Festblock(
                vorher: [
                    'Der Assistent hat sie an Sie übergeben: '.$beispiel(Platzhalter::Grund).'.',
                    'Der Inhalt steht **nicht** in dieser E-Mail — er gehört in kein Postfach und auf keinen Sperrbildschirm.',
                ],
                schaltflaeche: 'Im Posteingang öffnen',
                ziel: $link,
            )),
            default => $this->baue($art, $entwurf, [
                Platzhalter::Name->value => $beispiel(Platzhalter::Name),
                Platzhalter::Minuten->value => '10',
            ], new Festblock(vorher: [
                '**123456**',
                'Der Code gilt 10 Minuten und nur einmal.',
                'Haben Sie sich nicht gerade angemeldet? Dann kennt jemand Ihr Passwort — bitte ändern Sie es.',
            ])),
        };
    }

    /**
     * @param  array<string, string>  $werte
     */
    private function baue(Mailart $art, ?Mailtext $entwurf, array $werte, Festblock $block): MailMessage
    {
        $text = $entwurf ?? $this->vorlagen->fuerPlattform($art);

        $werte[Platzhalter::Produkt->value] = (string) config('app.name');

        return Mailaufbau::baue(Mailmarke::fuerPlattform(), Textbaustein::setze($text, $werte), $block);
    }
}
