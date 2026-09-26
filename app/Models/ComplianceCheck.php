<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\Ampel;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ein Pruefergebnis.
 *
 * **Rechtsstand, Regelwerksversion und Pruefdatum haengen daran**, damit ein
 * Befund nach einer Regelwerksaenderung mit seiner urspruenglichen Fassung
 * nachvollziehbar bleibt. Wer das nicht festhaelt, kann spaeter nicht sagen,
 * warum etwas damals durchging.
 *
 * @property Ampel $result
 * @property string|null $content_hash Fingerabdruck des geprueften Texts, Rohbytes
 * @property int $ruleset_version
 * @property CarbonImmutable $legal_as_of
 * @property CarbonImmutable $checked_at
 * @property string|null $findings
 * @property string|null $override_reason
 * @property CarbonImmutable|null $overridden_at
 */
class ComplianceCheck extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['checkable_id', 'overridden_by_user_id', 'content_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => Ampel::class,
            'ruleset_version' => 'integer',
            'legal_as_of' => 'immutable_date',
            'checked_at' => 'immutable_datetime',
            'overridden_at' => 'immutable_datetime',
            'findings' => Encrypted::class,
            'override_reason' => Encrypted::class,
        ];
    }

    /**
     * Die geprueften Texte tragen Behandlungsbezeichnungen, und die
     * Begruendung eines Overrides ist die Aussage eines Menschen.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['findings', 'override_reason'];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['result', 'ruleset_version'];
    }

    /** @return MorphTo<Model, $this> */
    public function checkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function befunde(): array
    {
        $roh = json_decode((string) $this->findings, true);

        /** @var list<array<string, string|null>> */
        return is_array($roh) ? $roh : [];
    }

    public function uebersteuert(): bool
    {
        return $this->overridden_at !== null;
    }

    /**
     * Darf das hinaus?
     *
     * Gruen -- oder rot mit einer Uebersteuerung, die jemand begruendet hat
     * (Entscheidung C3). **Gelb allein genuegt nicht**: es heisst, jemand muss
     * hinsehen, und das ist nicht dasselbe wie hingesehen haben.
     */
    public function gibtFrei(): bool
    {
        return $this->result->gibtFrei() || $this->uebersteuert();
    }
}
