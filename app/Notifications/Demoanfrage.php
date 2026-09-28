<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Contracts\Plattformmail;
use App\Models\DemoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Hinweis an den Vertrieb: eine Demo-Anfrage ist eingegangen (WP-38).
 *
 * **Ohne die Angaben der Anfrage.** Die Anfrage steht im Backoffice und
 * verschwindet dort nach ihrer Frist; eine Kopie in einem Postfach bliebe.
 * Die Mail sagt, **dass** eine kam und wann.
 *
 * **Die Anfrage als Kennung, nicht als Modell.** Was in der Schlange liegt,
 * liegt bei einem Fehler dauerhaft in failed_jobs -- mit dem Modell dort auch
 * Name und Adresse, wenn auch verschluesselt. Verschluesselt wird trotzdem:
 * so steht auch die Kennung nicht im Klartext.
 */
final class Demoanfrage extends Notification implements Plattformmail, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public readonly string $anfrage;

    public readonly string $eingang;

    public function __construct(DemoRequest $anfrage)
    {
        $this->anfrage = (string) $anfrage->uuid;
        $this->eingang = ($anfrage->created_at ?? CarbonImmutable::now())->toIso8601String();

        // `default`: auf die Mail wartet niemand, der vor dem Bildschirm sitzt
        // -- anders als auf einen Anmeldecode.
        $this->onQueue('default');
        $this->afterCommit();
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PlattformMailkanal::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(Plattformmails::class)->demoanfrage(
            CarbonImmutable::parse($this->eingang),
            url(route('backoffice.demoanfragen', [], false)),
        );
    }
}
