<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein Rohereignis.
 *
 * **Kein Protokoll, sondern ein Wiedervorlagestapel.** Es existiert, damit
 * eine fehlgeschlagene Verarbeitung erneut eingespielt werden kann -- 14 Tage
 * lang, nicht verlaengerbar (docs/integrationen/meta.md).
 *
 * Es enthaelt alles, was der Anbieter schickt, also auch Nachrichtentexte und
 * damit Personendaten -- unter Umstaenden nach Artikel 9. Deshalb
 * verschluesselt, und deshalb kurz.
 *
 * **Bewusst ohne Auditable.** Eine Praxis mit reger Kommunikation erzeugt
 * hunderte am Tag; jedes zu protokollieren waere ein Protokoll, in dem man
 * nichts mehr findet.
 *
 * @property ChannelType $channel
 * @property string $external_id
 * @property string $payload
 * @property CarbonImmutable|null $processed_at
 * @property string|null $failure
 * @property int $attempts
 * @property CarbonImmutable|null $created_at
 */
class ChannelRawEvent extends TenantModel implements HasPersonalData
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'payload' => Encrypted::class,
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['payload'];
    }

    /**
     * Die Nutzlast als Feld-Baum.
     *
     * @return array<string, mixed>
     */
    public function inhalt(): array
    {
        $daten = json_decode((string) $this->payload, true);

        return is_array($daten) ? $daten : [];
    }

    /**
     * @param  Builder<ChannelRawEvent>  $query
     * @return Builder<ChannelRawEvent>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }
}
