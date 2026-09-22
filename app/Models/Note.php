<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eine Notiz -- verschluesselt, mit Urheber.
 *
 * **Deshalb gibt es kein Freitextfeld am Termin.** Entscheidung P1 schliesst
 * Behandlungsdokumentation aus; ein Notizfeld ohne eigenen Ort fuellt sich
 * trotzdem damit, nur ohne Zweckbindung, ohne Frist und ohne die Frage, wer
 * es geschrieben hat. Hier steht all das daneben.
 *
 * @property string $notable_type
 * @property string $notable_id
 * @property string $body
 * @property string|null $author_user_id
 * @property CarbonImmutable|null $created_at
 */
class Note extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $fillable = ['notable_type', 'notable_id', 'body', 'author_user_id'];

    /** @var list<string> */
    protected $hidden = ['notable_id', 'author_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => Encrypted::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['body'];
    }

    /**
     * Nichts. Eine Notiz besteht aus ihrem Inhalt; ein Protokoll mit Wert
     * waere eine zweite, unverschluesselte Kopie (Entscheidung C5).
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return [];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
