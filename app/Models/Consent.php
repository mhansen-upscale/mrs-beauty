<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Einwilligung oder ihr Widerruf -- an der Kanalidentitaet
 * (Entscheidung D8).
 *
 * **Nicht an der Person.** Ein WhatsApp-Opt-in haengt an einer Rufnummer:
 * wer die Nummer wechselt, hat nicht zugestimmt, und wer zwei Nummern hat,
 * hat vielleicht nur fuer eine zugestimmt.
 *
 * **Eintraege werden fortgeschrieben, nicht ueberschrieben.** Jede Aenderung
 * ist eine eigene Zeile mit Zeitpunkt und Textstand. Die Beweislast liegt
 * beim Verantwortlichen, und "wir hatten damals einen anderen Text" ist kein
 * Nachweis.
 *
 * @property ConsentType $type
 * @property ConsentAction $action
 * @property string $channel_identity_id
 * @property string $text_version
 * @property string $text_snapshot
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 * @property-read ChannelIdentity $channelIdentity
 */
class Consent extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $fillable = [
        'channel_identity_id',
        'type',
        'action',
        'text_version',
        'text_snapshot',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    /** @var list<string> */
    protected $hidden = ['channel_identity_id', 'ip_address', 'user_agent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'action' => ConsentAction::class,
            'text_snapshot' => Encrypted::class,
            'ip_address' => Encrypted::class,
            'user_agent' => Encrypted::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['text_snapshot', 'ip_address', 'user_agent'];
    }

    /**
     * Typ und Richtung duerfen mit Wert ins Protokoll -- beide sagen nichts
     * ueber eine Person, und ohne sie waere ein Widerruf nicht nachweisbar.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['type', 'action'];
    }

    /**
     * @return BelongsTo<ChannelIdentity, $this>
     */
    public function channelIdentity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'channel_identity_id');
    }
}
