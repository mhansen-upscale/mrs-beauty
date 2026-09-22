<?php

declare(strict_types=1);

namespace App\Kanaele\Email;

use App\Kanaele\Eingangsnachricht;
use Carbon\CarbonImmutable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Macht aus einer rohen Mail das, was dieses Produkt behaelt.
 *
 * **Und nur das** (Regel 3). Eine Mail traegt zwanzig Kopfzeilen, zwei
 * Fassungen desselben Textes, Signaturbilder und die Wegmarken jedes
 * Relays. Aufgehoben werden Absender, Betreff, Text, die Kennung fuer den
 * Faden und die Dateien -- der Rest bleibt im Rohereignis und ist nach 14
 * Tagen weg.
 *
 * **Der Text, nicht das HTML.** Was ankommt, wird nirgends gerendert: ein
 * Mailrumpf ist die aelteste Stelle fuer eingebettete Skripte und externe
 * Bilder, und beides gehoert nicht in eine Inbox fuer Praxen. Gibt es nur
 * eine HTML-Fassung, wird sie in Text verwandelt.
 */
final class Mailleser
{
    public function __construct(private readonly MailMimeParser $parser = new MailMimeParser) {}

    public function lies(string $roh): ?Eingangsnachricht
    {
        $mail = $this->parser->parse($roh, false);

        $absender = $this->adresse($mail, 'From');

        if ($absender === null) {
            // Ohne Absender gibt es keine Kanalidentitaet -- und damit
            // niemanden, dem die Nachricht gehoert.
            return null;
        }

        return new Eingangsnachricht(
            externeId: $this->kennung($mail),
            absender: $absender,
            inhalt: $this->text($mail),
            medientyp: null,
            zeitpunkt: $this->zeitpunkt($mail),
            anzeigename: $this->name($mail),
            betreff: $this->betreff($mail),
            anhaenge: $this->anhaenge($mail),
        );
    }

    /** An welche unserer Adressen die Mail ging. */
    public function empfaenger(string $roh): ?string
    {
        $mail = $this->parser->parse($roh, false);

        // **Delivered-To zuerst.** Bei einer Weiterleitung -- und genau so
        // kommen Mails hier an -- steht in To die urspruengliche Adresse der
        // Praxis und nicht unsere.
        foreach (['Delivered-To', 'X-Original-To', 'To'] as $kopf) {
            $adresse = $this->adresse($mail, $kopf);

            if ($adresse !== null) {
                return $adresse;
            }
        }

        return null;
    }

    /**
     * Die Kennung des Fadens: Message-ID.
     *
     * Sie ist die externe Kennung dieser Nachricht und damit das, worueber
     * dedupliziert wird. Eine Mail ohne Message-ID gibt es kaum; wenn doch,
     * tritt an ihre Stelle ein Hash ueber das Ganze -- eine woertlich gleiche
     * Wiederholung ist dann dieselbe Mail.
     */
    private function kennung(IMessage $mail): string
    {
        $wert = $mail->getHeaderValue('Message-ID');

        // **Ohne spitze Klammern.** Der Parser gibt sie so, die Kopfzeile
        // traegt sie -- gespeichert wird die eine Form, sonst zeigen dieselbe
        // Mail zweimal zwei Kennungen und die Deduplizierung greift nicht.
        if (is_string($wert) && trim($wert, " \t<>") !== '') {
            return trim($wert, " \t<>");
        }

        return 'sha256:'.hash('sha256', (string) $mail->getHeaderValue('Date').(string) $mail->getHeaderValue('Subject'));
    }

    private function betreff(IMessage $mail): ?string
    {
        $wert = $mail->getHeaderValue('Subject');

        return is_string($wert) && trim($wert) !== '' ? trim($wert) : null;
    }

    private function text(IMessage $mail): ?string
    {
        $text = $mail->getTextContent();

        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $html = $mail->getHtmlContent();

        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        // Kein Rendern, kein Nachladen: die Auszeichnung faellt weg, der Text
        // bleibt.
        $ohneBloecke = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $mitUmbruechen = preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $ohneBloecke) ?? $ohneBloecke;

        $nurText = trim(html_entity_decode(strip_tags($mitUmbruechen), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $nurText === '' ? null : (string) preg_replace("/\n{3,}/", "\n\n", $nurText);
    }

    private function adresse(IMessage $mail, string $kopf): ?string
    {
        $header = $mail->getHeader($kopf);

        if (! $header instanceof AddressHeader) {
            return null;
        }

        $adresse = $header->getAddresses()[0] ?? null;
        $wert = $adresse?->getEmail();

        return is_string($wert) && $wert !== '' ? mb_strtolower($wert) : null;
    }

    private function name(IMessage $mail): ?string
    {
        $header = $mail->getHeader('From');

        if (! $header instanceof AddressHeader) {
            return null;
        }

        $erste = $header->getAddresses()[0] ?? null;
        $name = $erste?->getName();

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function zeitpunkt(IMessage $mail): ?CarbonImmutable
    {
        $wert = $mail->getHeaderValue('Date');

        if (! is_string($wert) || $wert === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($wert)->utc();
        } catch (\Throwable) {
            // Ein unlesbares Datum ist kein Grund, die Mail zu verwerfen --
            // dann gilt der Eingang.
            return null;
        }
    }

    /**
     * Die angehaengten Dateien.
     *
     * **Ohne eingebettete Bilder**: Signaturlogos und Zitatbilder kommen als
     * `inline` und wuerden den Anhangspeicher fluten, ohne dass sie je jemand
     * ansieht.
     *
     * @return list<array{name: string, inhalt: string}>
     */
    private function anhaenge(IMessage $mail): array
    {
        $grenze = (int) config('mrs.channels.email.max_attachment_bytes');
        $dateien = [];

        foreach ($mail->getAllAttachmentParts() as $nummer => $teil) {
            if (mb_strtolower((string) $teil->getContentDisposition()) === 'inline') {
                continue;
            }

            $inhalt = (string) $teil->getContent();

            if ($inhalt === '' || strlen($inhalt) > $grenze) {
                // Zu gross: der Anhang bleibt im Rohereignis und ist nach 14
                // Tagen weg. Die Alternative waere ein Speicher, der sich
                // ueber eine einzige Mail fuellen laesst.
                continue;
            }

            $dateien[] = ['name' => $this->dateiname($teil, $nummer), 'inhalt' => $inhalt];
        }

        return $dateien;
    }

    private function dateiname(IMessagePart $teil, int $nummer): string
    {
        $name = $teil->getFilename();

        // Nur der Name, nie ein Pfad: "../../" in einem Dateinamen ist ein
        // alter Trick und kommt aus einer fremden Quelle.
        $name = is_string($name) && trim($name) !== '' ? basename(trim($name)) : '';

        return $name === '' ? 'anhang-'.($nummer + 1) : $name;
    }
}
