<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use App\Contracts\Praxismail;
use App\Kanaele\Email\Postfach;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Der Kanal jeder Mail an eine Patientin: das Postfach der Praxis (B22).
 *
 * **Im Arbeiter, im Mandantenkontext, je Versand.** Die Nutzlast traegt nur
 * die UUID der Praxis; die Zugangsdaten werden erst hier entschluesselt --
 * mit dem Schluessel der Praxis, den der Arbeiter nur unter runAs() hat. In
 * der Warteschlange stehen sie nie.
 *
 * **Der Absender ist die Praxis**, nicht der, den die Mail vielleicht mitbringt:
 * Adresse und Anzeigename aus ihrem Postfach.
 */
final class PraxisMailkanal extends Versandkanal
{
    public function __construct(
        Factory $mailer,
        Markdown $markdown,
        private readonly Postfach $postfach,
        private readonly TenantContext $mandant,
    ) {
        parent::__construct($mailer, $markdown);
    }

    /**
     * @param  mixed  $notifiable
     *
     * @throws KeinPraxispostfach
     */
    public function send($notifiable, Notification $notification): ?SentMessage
    {
        if (! $notification instanceof Praxismail) {
            throw new LogicException($notification::class.' ist keine Praxismail (A15).');
        }

        $nachricht = method_exists($notification, 'toMail') ? $notification->toMail($notifiable) : null;

        if (! $nachricht instanceof MailMessage || ! is_object($notifiable) || ! $this->hatEmpfaenger($notifiable, $notification)) {
            return null;
        }

        $praxis = Organization::query()->whereUuid($notification->praxis())->first();

        if (! $praxis instanceof Organization) {
            throw new KeinPraxispostfach;
        }

        return $this->mandant->runAs($praxis, function () use ($notifiable, $notification, $nachricht): ?SentMessage {
            $verbindung = $this->postfach->verbindung();

            if (! $verbindung instanceof ChannelConnection || ! $verbindung->kannVersenden()) {
                throw new KeinPraxispostfach;
            }

            $nachricht->from((string) $verbindung->sender_id, $verbindung->display_name);

            return $this->sendeUeber($this->postfach->mailer($verbindung), $notifiable, $notification, $nachricht);
        });
    }
}
