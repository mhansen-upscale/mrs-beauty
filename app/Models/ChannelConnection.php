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
     * Schickt diese Praxis ueber ihr eigenes Postfach?
     *
     * Wenn nicht, geht die Post ueber den Versand der Plattform -- mit der
     * Adresse der Praxis im Absender, aber aus fremder Infrastruktur. Das
     * funktioniert nur, solange SPF und DKIM der Domain das zulassen.
     */
    public function hatEigenesPostfach(): bool
    {
        return is_string($this->smtp_host) && $this->smtp_host !== '' && $this->smtp_port !== null;
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
