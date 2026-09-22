<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\Email\Postfach;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Throwable;

/**
 * Schickt eine Probemail ueber das hinterlegte Postfach.
 *
 * **Auf der Queue, nicht im Anfragezyklus** (Regel 4): ein Mailserver, der
 * nicht antwortet, laesst sonst die Einstellungsseite haengen. Das Ergebnis
 * steht danach an der Verbindung -- geprueft oder mit Grund gescheitert --
 * und die Seite zeigt es beim naechsten Aufruf.
 *
 * Ein Postfach, das nie geprueft wurde, sieht im Produkt sonst genauso aus
 * wie eines, das nicht funktioniert.
 */
final class PostfachPruefen implements ShouldQueue
{
    use Queueable;

    /** Einmal. Falsche Zugangsdaten werden beim zweiten Versuch nicht richtig. */
    public int $tries = 1;

    public function __construct(
        private readonly string $organisation,
        private readonly string $empfaenger,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(TenantContext $mandant, Postfach $postfach): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($postfach, $organisation): void {
            $verbindung = ChannelConnection::query()
                ->where('channel', ChannelType::Email->value)
                ->first();

            if (! $verbindung instanceof ChannelConnection) {
                return;
            }

            try {
                $postfach->mailer($verbindung)->raw(
                    "Diese Nachricht bestätigt, dass der E-Mail-Versand für Ihre Praxis eingerichtet ist.\n\n"
                    ."Sie wurde von {$organisation->name} über die hinterlegten Zugangsdaten verschickt.",
                    function (Message $mail) use ($verbindung): void {
                        $mail->to($this->empfaenger)
                            ->from($verbindung->sender_id ?? $verbindung->external_id, $verbindung->display_name)
                            ->subject('Probemail: Ihr Postfach ist eingerichtet');
                    }
                );
            } catch (Throwable) {
                // **Ohne Klartext des Servers.** Eine SMTP-Fehlermeldung
                // traegt regelmaessig die Adresse des Empfaengers mit sich.
                $verbindung->meldeAusfall(ConnectionStatus::Expired, 'smtp_failed');

                return;
            }

            $verbindung->status = ConnectionStatus::Active;
            $verbindung->last_error = null;
            $verbindung->failed_at = null;
            $verbindung->verified_at = CarbonImmutable::now();
            $verbindung->save();
        });
    }
}
