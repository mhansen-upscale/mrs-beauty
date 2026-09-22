<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBinaryUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Das HWG-Regelwerk -- **global und versioniert** (Entscheidung C1).
 *
 * **Die eine Tabelle ohne organization_id.** Der Rechtsstand ist fuer alle
 * Mandanten derselbe; eine mandantenbezogene Kopie wuerde bedeuten, dass ein
 * Kunde mit veraltetem Regelwerk weiterarbeitet -- und genau das ist der Fall,
 * in dem eine Pruefhilfe schadet, statt zu helfen.
 *
 * Die Ausnahme steht in tests/Feature/Tenancy/ArchitekturTest.php namentlich.
 *
 * @property int $version
 * @property CarbonImmutable $legal_as_of
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property string|null $changelog
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 */
class ComplianceRuleset extends Model
{
    use HasBinaryUuid;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'legal_as_of' => 'immutable_date',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'reviewed_at' => 'immutable_date',
        ];
    }

    /**
     * **Ungeprueft, bis jemand mit Zulassung hingesehen hat.**
     *
     * specs/WP-30 nennt als Abnahmekriterium einen Testsatz echter Anzeigen,
     * "geprueft durch einen Medizinrechtler". Solange das aussteht, sagt das
     * Produkt es -- eine Ampel, der jemand vertraut, ohne dass sie geprueft
     * ist, ist gefaehrlicher als gar keine.
     */
    public function juristischGeprueft(): bool
    {
        return $this->reviewed_at !== null && is_string($this->reviewed_by) && $this->reviewed_by !== '';
    }

    public static function geltend(?CarbonImmutable $jetzt = null): ?self
    {
        $jetzt ??= CarbonImmutable::now();

        return self::query()
            ->where('valid_from', '<=', $jetzt->toDateString())
            ->where(function ($abfrage) use ($jetzt): void {
                $abfrage->whereNull('valid_until')->orWhere('valid_until', '>=', $jetzt->toDateString());
            })
            ->orderByDesc('version')
            ->first();
    }
}
