<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Datenschutz\Scanergebnis;
use App\Enums\AttachmentContext;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ein Anhang.
 *
 * Die Datei liegt **verschluesselt ausserhalb der Datenbank**, dieser
 * Datensatz haelt den Weg dorthin. Beim Loeschen muss beides weg -- sonst
 * bleiben Fotos auf dem Speicher liegen, waehrend der Datensatz verschwunden
 * ist. Deshalb loescht Loeschung nie ueber die Datenbank allein.
 *
 * **Chat-Anhaenge tragen ein Pflicht-Ablaufdatum** (Entscheidung C6). Eine
 * Praxis fuer aesthetische Behandlungen bekommt taeglich ungefragt zugesandte
 * Fotos; das sind Gesundheitsdaten nach Artikel 9 DSGVO, um die niemand
 * gebeten hat. Die Zusage steht als CHECK in der Datenbank und nicht nur hier.
 *
 * @property AttachmentContext $context
 * @property string $attachable_type
 * @property string $attachable_id
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property string $checksum
 * @property CarbonImmutable|null $scanned_at
 * @property string|null $scan_result
 * @property string|null $uploaded_by_user_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
class Attachment extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'uploaded_by_user_id',
        'context',
        'original_name',
        'path',
        'mime',
        'size_bytes',
        'checksum',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['attachable_id', 'uploaded_by_user_id', 'path', 'checksum'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => AttachmentContext::class,
            'original_name' => Encrypted::class,
            'size_bytes' => 'integer',
            'scanned_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Der Dateiname traegt regelmaessig einen Personenbezug --
     * "befund-mueller.pdf" sagt mehr, als es soll.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['original_name'];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['context', 'mime', 'scan_result'];
    }

    /**
     * Darf dieser Anhang ausgeliefert werden?
     *
     * Ein ungeprueftes oder beanstandetes Ergebnis heisst nein. Wer eine
     * Datei ausliefert, bevor sie geprueft ist, reicht weiter, was jemand
     * ungefragt geschickt hat.
     */
    public function istFreigegeben(): bool
    {
        if ($this->scanned_at === null || ! is_string($this->scan_result)) {
            return false;
        }

        // **Nur `clean` gibt frei.** `unscanned` heisst, dass niemand
        // hingesehen hat -- und was niemand geprueft hat, wird nicht
        // weitergereicht (WP-33).
        return Scanergebnis::tryFrom($this->scan_result)?->gibtFrei() ?? false;
    }

    /** Darf er im Browser erscheinen, statt heruntergeladen zu werden? */
    public function istBild(): bool
    {
        return in_array($this->mime, (array) config('mrs.attachments.inline_mimes', []), true);
    }

    /**
     * Hat die Pruefung diesen Anhang **beanstandet**?
     *
     * Der Gegenspieler zu istFreigegeben(), und nicht dessen Umkehrung:
     * dazwischen liegt `unscanned`.
     *
     * **Wo die strenge Regel gilt und wo nicht**, haengt daran, wer die Datei
     * geschickt hat. Ein ungefragt zugesandtes Foto aus dem Chat wird erst
     * ausgeliefert, wenn jemand hingesehen hat. Das Logo, das eine
     * Praxisinhaberin von ihrer eigenen Marke hochlaedt, ist ein anderer
     * Fall: dort blockiert nur ein **Befund**, nicht die fehlende Pruefung.
     *
     * Der Unterschied ist nicht Bequemlichkeit. Ohne angebundenen Scanner
     * steht ueberall `unscanned` -- die strenge Regel haette bedeutet, dass
     * ein Logo niemals erscheint, in keiner Installation ohne ClamAV.
     */
    public function istAbgelehnt(): bool
    {
        return is_string($this->scan_result)
            && Scanergebnis::tryFrom($this->scan_result) === Scanergebnis::Infected;
    }

    /**
     * @param  Builder<Attachment>  $query
     * @return Builder<Attachment>
     */
    public function scopeAbgelaufen(Builder $query, CarbonImmutable $jetzt): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', $jetzt);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
