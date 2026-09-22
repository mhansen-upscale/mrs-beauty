<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ChannelType;
use App\Kanaele\Email\EmailEingang;
use App\Kanaele\Email\EmailVersand;
use App\Kanaele\Kanaleingaenge;
use App\Kanaele\Kanalversender;
use App\Kanaele\WhatsApp\WhatsAppEingang;
use App\Kanaele\WhatsApp\WhatsAppVersand;
use Illuminate\Support\ServiceProvider;

/**
 * Wo sich die Kanaele eintragen.
 *
 * **Ein Ort, nicht vier.** Die Register aus WP-19 sind Singletons; wer sich
 * verstreut registriert, bekommt es erst dann mit, wenn eine Nachricht still
 * liegenbleibt -- ein fehlender Leser ist kein Fehlschlag, sondern ein
 * Rohereignis ohne Verarbeitung.
 *
 * Nach WP-20 stehen hier WhatsApp und E-Mail, jeder mit zwei Zeilen.
 * Messenger und Instagram sind zurueckgestellt (Entscheidung P11) --
 * ihre Faelle in ChannelType bleiben, weil eine Kennung erfasst werden darf;
 * bedient wird sie nicht.
 */
final class KanalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $eingaenge = $this->app->make(Kanaleingaenge::class);
        $versender = $this->app->make(Kanalversender::class);

        $eingaenge->registriere(ChannelType::WhatsApp, $this->app->make(WhatsAppEingang::class));
        $versender->registriere(ChannelType::WhatsApp, $this->app->make(WhatsAppVersand::class));

        $eingaenge->registriere(ChannelType::Email, $this->app->make(EmailEingang::class));
        $versender->registriere(ChannelType::Email, $this->app->make(EmailVersand::class));
    }
}
