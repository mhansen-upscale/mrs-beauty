<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Laravels Mailkanal -- mit einem Mailer, den der Kanal je Versand selbst baut
 * (A15).
 *
 * **Kein benannter Mailer.** MailManager merkt sich einen Mailer je Namen fuer
 * die Lebensdauer des Prozesses. Im Arbeiter hiesse das: die Zugangsdaten von
 * gestern -- und beim Postfach einer Praxis die der vorigen Praxis.
 */
abstract class Versandkanal extends MailChannel
{
    protected function sendeUeber(Mailer $mailer, object $notifiable, Notification $notification, MailMessage $nachricht): ?SentMessage
    {
        /** @var SentMessage|null */
        return $mailer->send(
            $this->buildView($nachricht),
            array_merge($nachricht->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $nachricht),
        );
    }

    /** Hat die Mail ueberhaupt eine Adresse? Wie im Original. */
    protected function hatEmpfaenger(object $notifiable, Notification $notification): bool
    {
        return method_exists($notifiable, 'routeNotificationFor')
            && (bool) $notifiable->routeNotificationFor('mail', $notification);
    }
}
