<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarPrivacyMode;
use App\Enums\CalendarProvider;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Die Verbindung eines Behandlers zu einem externen Kalender.
 *
 * Ein Behandler, ein Kalender, ein Anbieter -- der Unique-Index ueber
 * (practitioner_id, provider) sorgt dafuer. Zwei Verbindungen auf denselben
 * Kalender erzeugten doppelte Blocker.
 *
 * **Die Zugangsdaten liegen verschluesselt** (Regel 3). Sie sind im Wortsinn
 * keine Personendaten, sondern Schluessel zu welchen -- fuer die Maskierung
 * (Entscheidung C4) laeuft das auf dasselbe hinaus: eine Supportsitzung hat
 * dort nichts zu suchen.
 *
 * @property CalendarProvider $provider
 * @property CalendarConnectionStatus $status
 * @property CalendarPrivacyMode $privacy_mode
 * @property string $practitioner_id
 * @property string $calendar_id
 * @property string|null $account_email
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $access_expires_at
 * @property string $calendar_timezone
 * @property string|null $sync_token
 * @property string|null $channel_id
 * @property string|null $channel_resource_id
 * @property string|null $channel_token
 * @property CarbonImmutable|null $channel_expires_at
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $failed_at
 * @property-read Practitioner $practitioner
 */
class CalendarConnection extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'practitioner_id',
        'calendar_id',
        'account_email',
        'access_token',
        'refresh_token',
        'sync_token',
        'channel_token',
        'channel_resource_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CalendarProvider::class,
            'status' => CalendarConnectionStatus::class,
            'privacy_mode' => CalendarPrivacyMode::class,
            'calendar_id' => Encrypted::class,
            'account_email' => Encrypted::class,
            'access_token' => Encrypted::class,
            'refresh_token' => Encrypted::class,
            'access_expires_at' => 'immutable_datetime',
            'channel_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Der Kalendername ist bei Google in aller Regel eine E-Mail-Adresse,
     * also personenbezogen. Die beiden Token sind es nicht -- sie oeffnen
     * aber einen Kalender voller Personendaten, und das genuegt.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['calendar_id', 'account_email', 'access_token', 'refresh_token'];
    }

    /**
     * Anbieter, Zustand und Sichtbarkeitsmodus duerfen mit Wert ins Protokoll:
     * keiner der drei sagt etwas ueber eine Person (Entscheidung C5).
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['provider', 'status', 'privacy_mode'];
    }

    /**
     * Ist das Abonnement erneuerungsreif?
     *
     * Deutlich vor Ablauf, nicht kurz davor: faellt der Job einmal aus, ist
     * sonst der Sync tot (docs/integrationen/kalender.md, "Erneuerung").
     */
    public function brauchtErneuerung(CarbonImmutable $jetzt): bool
    {
        if ($this->channel_expires_at === null) {
            return true;
        }

        return $this->channel_expires_at->subHours($this->erneuerungsvorlauf())->lessThanOrEqualTo($jetzt);
    }

    /** Stunden vor Ablauf, ab denen erneuert wird -- je Anbieter. */
    public function erneuerungsvorlauf(): int
    {
        $wert = config('mrs.calendar.renew_before_expiry_hours.'.$this->provider->value);

        return is_numeric($wert)
            ? (int) $wert
            : (int) config('mrs.calendar.renew_before_expiry_hours.default', 24);
    }

    /** Laeuft das Zugangstoken in den naechsten Minuten ab? */
    public function zugangLaeuftAus(CarbonImmutable $jetzt): bool
    {
        return $this->access_token === null
            || $this->access_expires_at === null
            || $this->access_expires_at->subMinutes(5)->lessThanOrEqualTo($jetzt);
    }

    /**
     * Setzt die Verbindung auf "unterbrochen".
     *
     * R4: ein Ausfall erzeugt einen Hinweis **im Produkt**. Der Kurzgrund ist
     * ein Schluesselwort, niemals eine Fehlermeldung -- die traegt bei
     * Kalender-APIs gern die Adresse des Kontos mit sich.
     */
    public function meldeAusfall(string $grund): void
    {
        $this->status = CalendarConnectionStatus::Expired;
        $this->last_error = $grund;
        $this->failed_at = CarbonImmutable::now();
        $this->save();
    }

    /**
     * @param  Builder<CalendarConnection>  $query
     * @return Builder<CalendarConnection>
     */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query->where('status', CalendarConnectionStatus::Active->value);
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * @return HasMany<ExternalCalendarBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ExternalCalendarBlock::class);
    }

    /**
     * @return HasMany<CalendarEventLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class);
    }
}
