<?php

declare(strict_types=1);

namespace Tests\Feature\Kanaele;

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\Kanaleingaenge;
use App\Kanaele\Kanalversender;
use App\Models\ChannelConnection;
use App\Models\Organization;

/**
 * Eine Praxis mit einer Kanalverbindung und einem angebundenen Testkanal.
 */
final class Kanalaufbau
{
    public readonly Organization $organisation;

    public readonly ChannelConnection $verbindung;

    public readonly Testkanal $kanal;

    public function __construct(?Organization $organisation = null, string $seite = 'seite-4711')
    {
        $this->organisation = alsMandant($organisation);

        $verbindung = new ChannelConnection;
        $verbindung->channel = ChannelType::Messenger;
        $verbindung->status = ConnectionStatus::Active;
        $verbindung->external_id = $seite;
        $verbindung->display_name = 'Demo-Praxis';
        $verbindung->access_token = 'systembenutzer-token';
        $verbindung->save();

        $this->verbindung = $verbindung;

        $this->kanal = new Testkanal;

        app(Kanaleingaenge::class)->registriere(ChannelType::Messenger, $this->kanal);
        app(Kanalversender::class)->registriere(ChannelType::Messenger, $this->kanal);
    }
}
