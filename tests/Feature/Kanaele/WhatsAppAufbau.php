<?php

declare(strict_types=1);

namespace Tests\Feature\Kanaele;

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Models\ChannelConnection;
use App\Models\Organization;

/**
 * Eine Praxis mit angebundener WhatsApp-Rufnummer.
 *
 * **Zwei Kennungen**: die Zustellung traegt die WABA-Kennung, der Versand
 * laeuft ueber die Rufnummern-ID. Genau diese Verwechslung soll der Aufbau
 * sichtbar machen -- beide Werte sind hier verschieden.
 */
final class WhatsAppAufbau
{
    public readonly Organization $organisation;

    public readonly ChannelConnection $verbindung;

    public const WABA = 'waba-4711';

    public const RUFNUMMER = 'nummer-99';

    public function __construct(?Organization $organisation = null)
    {
        $this->organisation = alsMandant($organisation);

        $verbindung = new ChannelConnection;
        $verbindung->channel = ChannelType::WhatsApp;
        $verbindung->status = ConnectionStatus::Active;
        $verbindung->external_id = self::WABA;
        $verbindung->sender_id = self::RUFNUMMER;
        $verbindung->display_name = 'Demo-Praxis';
        $verbindung->access_token = 'systembenutzer-token';
        $verbindung->save();

        $this->verbindung = $verbindung;
    }

    /**
     * Eine Zustellung, wie die Cloud API sie schickt.
     *
     * @param  array<string, mixed>  $nachricht
     * @return array<string, mixed>
     */
    public static function zustellung(array $nachricht, string $absender = '4915112345678', ?string $name = 'Annika Müller'): array
    {
        $wert = [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '4930123456', 'phone_number_id' => self::RUFNUMMER],
            'messages' => [$nachricht + ['from' => $absender]],
        ];

        if ($name !== null) {
            $wert['contacts'] = [['profile' => ['name' => $name], 'wa_id' => $absender]];
        }

        return [
            'id' => self::WABA,
            'changes' => [['field' => 'messages', 'value' => $wert]],
        ];
    }

    /**
     * Eine Statusrueckmeldung.
     *
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    public static function statusmeldung(string $kennung, string $status, array $zusatz = []): array
    {
        return [
            'id' => self::WABA,
            'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'statuses' => [array_merge([
                    'id' => $kennung,
                    'status' => $status,
                    'timestamp' => '1800000000',
                    'recipient_id' => '4915112345678',
                ], $zusatz)],
            ]]],
        ];
    }
}
