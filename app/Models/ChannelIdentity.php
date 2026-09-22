<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Contracts\UsesBlindIndexes;
use App\Enums\ChannelType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlindIndexes;
use App\Models\Concerns\MasksPersonalData;
use App\Support\BlindIndex;
use App\Support\Telefonnummer;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unter welcher Kennung ein Mensch bei einem Anbieter gefuehrt wird
 * (Entscheidung D5).
 *
 * **Eine Identitaet ist nicht die Person.** Wer beides gleichsetzt, fuehrt
 * beim ersten geteilten Familienanschluss zwei Menschen zusammen. Der Bezug
 * auf den Kontakt ist deshalb nullable: die erste Nachricht kommt an, bevor
 * jemand weiss, wer da schreibt.
 *
 * Eindeutig ist das Tripel aus Organisation, Kanal und Kennung. Meta vergibt
 * Nutzerkennungen je Seite unterschiedlich, und zwei Organisationen, die
 * dieselbe Kennung sehen, sehen zwei verschiedene Identitaeten (D10).
 *
 * @property ChannelType $channel
 * @property string $external_id
 * @property string|null $display_name
 * @property string|null $contact_id
 * @property CarbonImmutable|null $last_seen_at
 * @property-read Contact|null $contact
 */
class ChannelIdentity extends TenantModel implements HasPersonalData, UsesBlindIndexes
{
    use Auditable;
    use HasBlindIndexes;
    use MasksPersonalData;

    protected $fillable = ['channel', 'external_id', 'display_name', 'contact_id', 'last_seen_at'];

    /** @var list<string> */
    protected $hidden = ['contact_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'external_id' => Encrypted::class,
            'display_name' => Encrypted::class,
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function blindIndexes(): array
    {
        return ['external_id' => 'external_id_bidx'];
    }

    /**
     * Eine Rufnummer wird vor dem Index nach E.164 gebracht -- sonst waeren
     * dieselbe Nummer in zwei Schreibweisen zwei Identitaeten.
     *
     * Bei den uebrigen Kanaelen ist die Kennung undurchsichtig und wird
     * verglichen, wie sie kommt.
     */
    public function blindIndexValue(string $feld, mixed $wert): ?string
    {
        if (! is_string($wert) || $wert === '') {
            return null;
        }

        return $this->channel->istRufnummer() ? Telefonnummer::e164($wert) : $wert;
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['external_id', 'display_name'];
    }

    /**
     * Der Kanal und der Zeitpunkt duerfen mit Wert ins Protokoll -- die
     * Kennung selbst nicht (Entscheidung C5).
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['channel'];
    }

    /**
     * Suche ueber die exakte Kennung eines Kanals.
     *
     * Eigener Weg statt whereBlind(): die Normalisierung haengt am Kanal, und
     * den kennt eine leere Modellinstanz nicht.
     *
     * @param  Builder<ChannelIdentity>  $query
     * @return Builder<ChannelIdentity>
     */
    public function scopeMitKennung(Builder $query, ChannelType $kanal, string $kennung): Builder
    {
        $vergleichbar = $kanal->istRufnummer() ? Telefonnummer::e164($kennung) : trim($kennung);

        if ($vergleichbar === null || $vergleichbar === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('channel', $kanal->value)
            ->where('external_id_bidx', BlindIndex::hash($vergleichbar, $this->indexschluessel()));
    }

    public function kennungAnzeige(): string
    {
        return $this->channel->istRufnummer()
            ? Telefonnummer::anzeige($this->external_id)
            : $this->external_id;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    private function indexschluessel(): string
    {
        $organisation = $this->getAttribute('organization_id');

        if (! is_string($organisation) || $organisation === '') {
            $organisation = app(TenantContext::class)->requireId();
        }

        return app(KeyRing::class)->for($organisation)->blindIndexKey;
    }
}
