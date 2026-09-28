<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use App\Models\ChannelConnection;
use App\Models\PlatformMailSetting;

/**
 * Ein Mailserver, so beschrieben, wie Laravel ihn wirklich liest.
 *
 * **Laravel liest `encryption` nicht.** MailManager::createSmtpTransport()
 * kennt nur `scheme` und schliesst aus Port 465 auf implizites TLS; STARTTLS
 * erzwingt erst die Symfony-Option `require_tls`. Bis zum 28.09.2026 gab
 * Postfach `encryption` mit -- und jede Einstellung verhielt sich gleich.
 *
 * - `ssl` oder Port 465: implizites TLS (`smtps`)
 * - `tls`: STARTTLS, **erzwungen** -- ein Server, der es nicht anbietet,
 *   bekommt die Mail nicht im Klartext
 * - keine: kein STARTTLS, auch kein angebotenes
 */
final class Smtpzugang
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $verschluesselung,
        #[\SensitiveParameter]
        public readonly ?string $benutzer,
        #[\SensitiveParameter]
        public readonly ?string $passwort,
    ) {}

    public static function ausVerbindung(ChannelConnection $verbindung): self
    {
        return new self(
            (string) $verbindung->smtp_host,
            (int) $verbindung->smtp_port,
            $verbindung->smtp_encryption,
            $verbindung->smtp_username,
            $verbindung->smtp_password,
        );
    }

    public static function ausEinstellung(PlatformMailSetting $einstellung): self
    {
        return new self(
            (string) $einstellung->smtp_host,
            (int) $einstellung->smtp_port,
            $einstellung->smtp_encryption,
            $einstellung->smtp_username,
            $einstellung->smtp_password,
        );
    }

    /**
     * Fuer Mail::build() -- ueber einen eigenen Transportnamen, damit Tests ihn
     * gegen eine Attrappe tauschen koennen (Mail::fake() faengt build() nicht).
     *
     * @return array<string, mixed>
     */
    public function konfiguration(string $transport): array
    {
        $implizit = $this->port === 465 || $this->verschluesselung === 'ssl';

        return [
            'transport' => $transport,
            'scheme' => $implizit ? 'smtps' : 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->benutzer,
            'password' => $this->passwort,
            'timeout' => (int) config('mrs.mail.smtp_timeout'),
            'require_tls' => ! $implizit && $this->verschluesselung === 'tls',
            'auto_tls' => $implizit || $this->verschluesselung !== null,
        ];
    }
}
