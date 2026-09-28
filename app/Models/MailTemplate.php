<?php

declare(strict_types=1);

namespace App\Models;

use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Enums\Mailart;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Die Vorlage einer Praxis fuer eine ihrer Terminmails (WP-36, P12).
 *
 * **Eine Ueberschreibung, keine Kopie** (D15): die Zeile gibt es nur, wenn
 * die Praxis etwas anders will. Zuruecksetzen loescht sie.
 *
 * @property Mailart $template
 * @property string $subject
 * @property string $greeting
 * @property string $intro
 * @property string $outro
 * @property string $salutation
 * @property CarbonImmutable|null $updated_at
 */
class MailTemplate extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => Mailart::class,
        ];
    }

    /**
     * **Nur die Mailart mit Wert** (C5, C17): der Text einer Vorlage ist kein
     * Personendatum, aber das Protokoll ist nicht der Ort, ihn zu fuehren --
     * es sagt, dass und wer, nicht was.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['template'];
    }

    public function text(): Mailtext
    {
        return Mailtext::aus($this->only(['subject', 'greeting', 'intro', 'outro', 'salutation']));
    }

    /**
     * Die juengste HWG-Pruefung (WP-30) -- ein Hinweis, keine Sperre (P12).
     *
     * @return MorphOne<ComplianceCheck, $this>
     */
    public function pruefung(): MorphOne
    {
        return $this->morphOne(ComplianceCheck::class, 'checkable')
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], 'max');
    }

    /** Was die HWG-Pruefung liest: der Text ohne Betreff. */
    public function pruefbarerText(): string
    {
        return trim(implode("\n\n", array_filter([$this->greeting, $this->intro, $this->outro, $this->salutation])));
    }
}
