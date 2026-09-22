<?php

declare(strict_types=1);

namespace Tests\Feature\Kanaele;

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Models\ChannelConnection;
use App\Models\Organization;

/**
 * Eine Praxis mit E-Mail-Kanal.
 *
 * **Zwei Adressen**, wie bei WhatsApp zwei Kennungen: `external_id` ist die
 * Eingangsadresse bei uns -- darueber findet eine Zustellung ihren Mandanten
 * --, `sender_id` die Adresse der Praxis, unter der geantwortet wird.
 */
final class MailAufbau
{
    public readonly Organization $organisation;

    public readonly ChannelConnection $verbindung;

    public const EINGANG = 'demo-praxis@inbound.mrs-beauty.test';

    public const ABSENDER = 'praxis@demo-praxis.de';

    public function __construct(?Organization $organisation = null)
    {
        $this->organisation = alsMandant($organisation);

        $verbindung = new ChannelConnection;
        $verbindung->channel = ChannelType::Email;
        $verbindung->status = ConnectionStatus::Active;
        $verbindung->external_id = self::EINGANG;
        $verbindung->sender_id = self::ABSENDER;
        $verbindung->display_name = 'Demo-Praxis';
        $verbindung->save();

        $this->verbindung = $verbindung;
    }

    /**
     * Eine Mail, wie sie ankommt.
     *
     * @param  list<string>  $zusatzkoepfe
     */
    public static function mail(
        string $von = 'Annika Müller <annika@example.test>',
        string $betreff = 'Frage zum Termin',
        string $text = "Guten Tag,\n\nhaben Sie nächste Woche etwas frei?\n\nViele Grüße",
        string $kennung = '<abc-1@example.test>',
        array $zusatzkoepfe = [],
        ?string $an = null,
    ): string {
        $koepfe = array_merge([
            'Return-Path: <annika@example.test>',
            'Delivered-To: '.($an ?? self::EINGANG),
            'From: '.$von,
            'To: '.self::ABSENDER,
            'Subject: '.$betreff,
            'Message-ID: '.$kennung,
            'Date: Tue, 12 Jan 2027 09:30:00 +0100',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ], $zusatzkoepfe);

        return implode("\r\n", $koepfe)."\r\n\r\n".$text."\r\n";
    }

    /** Eine Mail mit Anhang -- mehrteilig, wie jedes Mailprogramm sie baut. */
    public static function mitAnhang(string $dateiname = 'befund.pdf', string $inhalt = '%PDF-1.4 Testinhalt'): string
    {
        $grenze = 'grenze-4711';

        $koepfe = implode("\r\n", [
            'Delivered-To: '.self::EINGANG,
            'From: Annika Müller <annika@example.test>',
            'To: '.self::ABSENDER,
            'Subject: Unterlagen',
            'Message-ID: <mit-anhang@example.test>',
            'Date: Tue, 12 Jan 2027 09:30:00 +0100',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$grenze.'"',
        ]);

        return $koepfe."\r\n\r\n"
            .'--'.$grenze."\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            ."Anbei die Unterlagen.\r\n"
            .'--'.$grenze."\r\n"
            .'Content-Type: application/pdf; name="'.$dateiname.'"'."\r\n"
            .'Content-Disposition: attachment; filename="'.$dateiname.'"'."\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($inhalt), 76, "\r\n")
            .'--'.$grenze."--\r\n";
    }
}
