<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\ConnectionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Das Werbekonto einer Praxis.
 *
 * **Es gehoert dem Kunden** (Entscheidung B1). Wir greifen ueber eine
 * Partnerschaft im Business Manager darauf zu; bei Kuendigung nehmen wir
 * unseren Zugriff zurueck und nicht sein Konto mit.
 *
 * **Systembenutzer-Token, kein Nutzertoken** (docs/integrationen/meta.md):
 * ein Nutzertoken stirbt mit dem Ausscheiden des Mitarbeiters, der sich
 * damals angemeldet hat -- und dann steht die Werbung einer Praxis still,
 * ohne dass jemand weiss, warum.
 *
 * @property string $external_id
 * @property string|null $business_external_id
 * @property string|null $page_external_id
 * @property string|null $name
 * @property string|null $currency
 * @property string|null $timezone
 * @property ConnectionStatus $status
 * @property string|null $access_token
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $failed_at
 * @property-read Collection<int, AdCampaign> $campaigns
 */
class AdAccount extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    /**
     * Wie lang ein Ausfallgrund hoechstens wird.
     *
     * Metas laengster bisher gesehener Satz -- die Sicherheitspruefung -- hat
     * 260 Zeichen. Tausend sind mehr als genug und wenig genug fuer einen
     * Hinweiskasten.
     */
    private const GRUND_MAX = 1000;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['access_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'access_token' => Encrypted::class,
            'token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Das Token ist im Wortsinn kein Personendatum, sondern der Schluessel
     * zum Geld der Praxis -- fuer die Maskierung laeuft das auf dasselbe
     * hinaus (Entscheidung C4).
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['access_token'];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'external_id'];
    }

    /** @return HasMany<AdCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function istVerbunden(): bool
    {
        return $this->disconnected_at === null
            && is_string($this->getAttributes()['access_token'] ?? null);
    }

    /**
     * Meldet einen Ausfall nach der Fehlertabelle aus
     * `docs/integrationen/meta.md`.
     *
     * Ohne Wiederholung: ein ungueltiges Token wird beim zwanzigsten Versuch
     * nicht gueltiger, und die Wiederholungen verdecken, dass jemand die
     * Verbindung erneuern muss.
     */
    public function meldeAusfall(ConnectionStatus $zustand, string $grund): void
    {
        $this->status = $zustand;

        // **Der Vermerk darf die Zeile nicht sprengen.** Die Spalte ist
        // `text`, die Grenze steht hier -- wie bei sync_error in
        // GehoertZurWerbestruktur. Das Festhalten eines Ausfalls darf nie
        // selbst fehlschlagen: sonst ist mit dem Auftrag auch der Grund fort
        // (24.09.2026, zweimal am selben Tag).
        $this->last_error = Str::limit($grund, self::GRUND_MAX - 2, ' …');
        $this->failed_at = CarbonImmutable::now();
        $this->save();
    }

    public function meldeErfolg(?CarbonImmutable $jetzt = null): void
    {
        $this->status = ConnectionStatus::Active;
        $this->last_error = null;
        $this->failed_at = null;
        $this->last_synced_at = $jetzt ?? CarbonImmutable::now();
        $this->save();
    }
}
