<?php

declare(strict_types=1);

namespace App\Kanaele\Email;

use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Kanaele\Kanalfehler;
use App\Kanaele\Kanalversand;
use App\Kanaele\Versandergebnis;
use App\Models\ChannelConnection;
use App\Models\Message;
use App\Support\Fehlereinordnung;
use Illuminate\Mail\Message as Mailrumpf;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Antworten per Mail.
 *
 * **Im Faden, nicht daneben.** Eine Antwort ohne `In-Reply-To` erscheint beim
 * Empfaenger als neue Mail; wer dreimal schreibt, hat drei Gespraeche im
 * Postfach und wir eines. Deshalb traegt jede ausgehende Mail die Kennung
 * der letzten eingehenden -- und eine eigene, unter der die Antwort darauf
 * wiedergefunden wird.
 *
 * **Absender ist die Praxis, Rueckweg sind wir.** `From` ist die Adresse der
 * Praxis, `Reply-To` die Eingangsadresse dieses Mandanten: sonst landet die
 * Antwort im Postfach der Praxis und nicht in der Inbox, und die Haelfte des
 * Gespraechs fehlt.
 *
 * **Ueber welchen Weg**, entscheidet Postfach: das eigene der Praxis, wenn
 * hinterlegt, sonst der Versand der Plattform.
 */
final class EmailVersand implements Kanalversand
{
    public function __construct(private readonly Postfach $postfach) {}

    public function sende(ChannelConnection $verbindung, Message $nachricht): Versandergebnis
    {
        $konversation = $nachricht->conversation;
        $empfaenger = $konversation->channelIdentity->external_id;

        if (! filter_var($empfaenger, FILTER_VALIDATE_EMAIL)) {
            throw new Kanalfehler(new Fehlereinordnung('invalid_recipient', wiederholen: false, zustand: null));
        }

        $kennung = $this->eigeneKennung($verbindung);
        $bezug = $this->letzteEingehende($nachricht);

        try {
            $this->postfach->mailer($verbindung)->raw((string) $nachricht->body, function (Mailrumpf $mail) use (
                $verbindung,
                $empfaenger,
                $nachricht,
                $kennung,
                $bezug,
            ): void {
                $mail->to($empfaenger)
                    ->from($verbindung->sender_id ?? $verbindung->external_id, $verbindung->display_name)
                    ->replyTo($verbindung->external_id)
                    ->subject($this->betreff($nachricht));

                $kopf = $mail->getSymfonyMessage()->getHeaders();

                // Die eigene Kennung wird **gesetzt**, nicht abgewartet: sie
                // ist die externe Kennung dieser Nachricht, und ohne sie
                // liesse sich eine Antwort darauf keinem Faden zuordnen.
                $kopf->addIdHeader('Message-ID', $kennung);

                if ($bezug !== null) {
                    $kopf->addIdHeader('In-Reply-To', $bezug);
                    $kopf->addIdHeader('References', $bezug);
                }
            });
        } catch (TransportExceptionInterface $ausnahme) {
            // Ein Transportfehler ist voruebergehend: der Mailserver ist
            // gerade nicht erreichbar. Das wird wiederholt -- anders als eine
            // Ablehnung, die beim zwanzigsten Versuch dieselbe bleibt.
            throw new Kanalfehler(new Fehlereinordnung('mail_transport', wiederholen: true, zustand: null));
        }

        // **Ohne Kosten, und das ist keine Schaetzung.** Eine Mail hat keinen
        // Preis je Nachricht -- anders als WhatsApp, wo `none` eine Aussage
        // waere, die niemand geprueft hat.
        return new Versandergebnis($kennung, MessageCostCategory::None);
    }

    /**
     * Eine Kennung im eigenen Namensraum.
     *
     * Der Teil hinter dem @ ist die Eingangsadresse dieses Mandanten: damit
     * ist die Kennung weltweit eindeutig, ohne dass wir etwas zaehlen muessen.
     */
    private function eigeneKennung(ChannelConnection $verbindung): string
    {
        $herkunft = explode('@', $verbindung->external_id)[1] ?? 'mrs.local';

        // Ohne spitze Klammern, wie sie auch aus dem Leser kommt: dieselbe
        // Form auf beiden Wegen, sonst findet eine Antwort ihren Faden nicht.
        return bin2hex(random_bytes(16)).'@'.$herkunft;
    }

    /** Die Kennung der letzten eingehenden Nachricht dieses Gespraechs. */
    private function letzteEingehende(Message $nachricht): ?string
    {
        $vorige = Message::query()
            ->where('conversation_id', $nachricht->conversation_id)
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNotNull('external_id')
            ->orderByDesc('created_at')
            ->first();

        $kennung = $vorige?->external_id;

        // Ein selbst gebildeter Ersatz ("sha256:...") ist keine Message-ID
        // und gehoert in keinen Kopf.
        return is_string($kennung) && $kennung !== '' && ! str_starts_with($kennung, 'sha256:')
            ? $kennung
            : null;
    }

    /**
     * Der Betreff: der eigene, sonst der des Gespraechs mit "Re:".
     *
     * Ohne Betreff landet eine Mail in manchen Postfaechern im Spam -- und im
     * Ueberblick des Empfaengers sieht sie nach Werbung aus.
     */
    private function betreff(Message $nachricht): string
    {
        if (is_string($nachricht->subject) && $nachricht->subject !== '') {
            return $nachricht->subject;
        }

        $vorige = Message::query()
            ->where('conversation_id', $nachricht->conversation_id)
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNotNull('subject')
            ->orderByDesc('created_at')
            ->first();

        $betreff = $vorige?->subject;

        if (! is_string($betreff) || $betreff === '') {
            return (string) config('mrs.channels.email.default_subject');
        }

        return str_starts_with(mb_strtolower($betreff), 're:') ? $betreff : 'Re: '.$betreff;
    }
}
