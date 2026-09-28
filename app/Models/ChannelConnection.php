<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Der Zugang eines Mandanten zu einem Kanal.
 *
 * **Systembenutzer-Token, kein Nutzertoken** (docs/integrationen/meta.md):
 * ein Nutzertoken wird mit dem Ausscheiden eines Mitarbeiters ungueltig, und
 * dann steht die Kommunikation einer Praxis still, ohne dass jemand weiss,
 * warum.
 *
 * Der Zustand folgt der Fehlertabelle des Leitfadens. Ein ungueltiges Token
 * ist etwas anderes als eine fehlende Berechtigung -- wer beides gleich
 * behandelt und wiederholt, verdeckt, dass jemand etwas tun muss.
 *
 * @property ChannelType $channel
 * @property ConnectionStatus $status
 * @property string $external_id
 * @property string|null $display_name
 * @property string|null $sender_id
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property CarbonImmutable|null $verified_at
 * @property string|null $access_token
 * @property string|null $webhook_secret
 * @property CarbonImmutable|null $token_expires_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $failed_at
 */
class ChannelConnection extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['access_token', 'webhook_secret', 'smtp_username', 'smtp_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'status' => ConnectionStatus::class,
            'access_token' => Encrypted::class,
            'webhook_secret' => Encrypted::class,
            'smtp_username' => Encrypted::class,
            'smtp_password' => Encrypted::class,
            'smtp_port' => 'integer',
            'verified_at' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Die beiden Geheimnisse sind im Wortsinn keine Personendaten, sondern
     * Schluessel zu welchen -- fuer die Maskierung laeuft das auf dasselbe
     * hinaus (Entscheidung C4).
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['access_token', 'webhook_secret', 'smtp_username', 'smtp_password'];
    }

    /**
     * Hat diese Praxis einen eigenen Mailserver hinterlegt?
     *
     * Wenn nicht, geht **keine** Mail an eine Patientin hinaus (B22) -- bis
     * zum 28.09.2026 ging sie dann ueber den Versand der Plattform.
     */
    public function hatEigenesPostfach(): bool
    {
        return is_string($this->smtp_host) && $this->smtp_host !== '' && $this->smtp_port !== null;
    }

    /**
     * Kann ueber diese Verbindung eine Mail an eine Patientin hinausgehen?
     *
     * Eigener Server, eine Absenderadresse, und die Verbindung ist nicht als
     * gestoert gemeldet (die Probemail setzt `expired`, wenn sie scheitert).
     */
    public function kannVersenden(): bool
    {
        return $this->channel === ChannelType::Email
            && $this->hatEigenesPostfach()
            && is_string($this->sender_id) && $this->sender_id !== ''
            && in_array($this->status, [ConnectionStatus::Active, ConnectionStatus::Degraded], true);
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['channel', 'status'];
    }

    /**
     * Meldet einen Ausfall nach der Fehlertabelle.
     *
     * Ohne Wiederholung: ein ungueltiges Token wird beim zwanzigsten Versuch
     * nicht gueltiger, und die Wiederholungen verdecken, dass die Verbindung
     * erneuert werden muss.
     */
    public function meldeAusfall(ConnectionStatus $zustand, string $grund): void
    {
        $this->status = $zustand;
        $this->last_error = $grund;
        $this->failed_at = CarbonImmutable::now();
        $this->save();
    }

    /**
     * @param  Builder<ChannelConnection>  $query
     * @return Builder<ChannelConnection>
     */
    public function scopeSendebereit(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ConnectionStatus::Active->value,
            ConnectionStatus::Degraded->value,
        ]);
    }
}
