<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Eine Behandlung aus dem Leistungskatalog.
 *
 * **Der Katalog ist zweierlei.** Er ist die einzige Quelle fuer
 * Behandlungsnamen und Preise -- der Agent darf nichts sagen, was hier nicht
 * steht (Entscheidungen D2 und G5). Und er ist zugleich die Sperrliste: jeder
 * Name, der hier steht, darf **nie** an Meta gehen (Regel 2 in CLAUDE.md,
 * abgesichert durch den Test in WP-32).
 *
 * Wer den Katalog um einen Namen erweitert, erweitert damit beides.
 *
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $category
 * @property int|null $price_from_cents
 * @property int|null $price_to_cents
 * @property int $avg_revenue_cents
 * @property bool $is_active
 * @property bool $all_practitioners
 */
class Treatment extends TenantModel
{
    use Auditable;

    /** @use HasFactory<TreatmentFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'price_from_cents',
        'price_to_cents',
        'avg_revenue_cents',
        'is_active',
        'all_practitioners',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_from_cents' => 'integer',
            'price_to_cents' => 'integer',
            'avg_revenue_cents' => 'integer',
            'is_active' => 'boolean',
            'all_practitioners' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['is_active', 'avg_revenue_cents', 'price_from_cents', 'price_to_cents'];
    }

    /**
     * Die aktiven Katalognamen der geltenden Organisation.
     *
     * Zwei Aufrufer, mit entgegengesetzter Absicht:
     *
     * - **WP-22/WP-23** pruefen damit, ob ein vom Agenten erzeugter
     *   Behandlungsname im Katalog steht. Steht er nicht drin, wird nicht
     *   gesendet.
     * - **WP-32** prueft damit, ob ein ausgehender Meta-Payload einen
     *   Katalognamen enthaelt. Steht er drin, schlaegt der Test fehl.
     *
     * @return list<string>
     */
    public static function aktiveNamen(): array
    {
        /** @var list<string> */
        return self::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->map(strval(...))
            ->values()
            ->toArray();
    }

    /**
     * Die juengste HWG-Pruefung dessen, was die Buchungsseite zeigen wuerde
     * (WP-30).
     *
     * @return MorphOne<ComplianceCheck, $this>
     */
    public function pruefung(): MorphOne
    {
        return $this->morphOne(ComplianceCheck::class, 'checkable')
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], 'max');
    }

    /**
     * Der Preis, wie ihn die Buchungsseite nennt -- oder nichts.
     *
     * "ab 250 €" oder "250–400 €". Eine Obergrenze ohne Untergrenze laesst der
     * Katalog nicht zu.
     */
    public function preistext(): ?string
    {
        if ($this->price_from_cents === null) {
            return null;
        }

        $ab = self::euro($this->price_from_cents);

        return $this->price_to_cents === null || $this->price_to_cents === $this->price_from_cents
            ? 'ab '.$ab
            : str_replace(' €', '', $ab).'–'.self::euro($this->price_to_cents);
    }

    /**
     * Alles, was die Buchungsseite ueber diese Behandlung sagen wuerde -- und
     * damit alles, was die HWG-Pruefung sehen muss. Preis und Beschreibung
     * zusammen: ein Preis kann selbst Werbung sein ("nur heute").
     */
    public function oeffentlicherText(): string
    {
        return trim(implode("\n", array_filter([
            is_string($this->description) ? trim($this->description) : null,
            $this->preistext(),
        ])));
    }

    private static function euro(int $cent): string
    {
        return number_format($cent / 100, $cent % 100 === 0 ? 0 : 2, ',', '.').' €';
    }

    /**
     * @param  Builder<Treatment>  $query
     * @return Builder<Treatment>
     */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Wer diese Behandlung beherrscht.
     *
     * Leer **und** `all_practitioners` false heisst: niemand. Leer und
     * `all_practitioners` true heisst: alle aktiven. Ein leerer Pivot allein
     * waere zweideutig -- "alle" oder "noch nicht gepflegt"?
     *
     * @return BelongsToMany<Practitioner, $this, TreatmentPractitioner>
     */
    public function practitioners(): BelongsToMany
    {
        return $this->belongsToMany(Practitioner::class, 'treatment_practitioner')
            ->using(TreatmentPractitioner::class)
            ->withTimestamps();
    }

    /**
     * Die Behandler, die diese Behandlung machen -- aufgeloest.
     *
     * @return Collection<int, Practitioner>
     */
    public function behandler(): Collection
    {
        if ($this->all_practitioners) {
            /** @var Collection<int, Practitioner> */
            return Practitioner::query()->where('is_active', true)->orderBy('last_name')->get();
        }

        /** @var Collection<int, Practitioner> */
        return $this->practitioners()->where('practitioners.is_active', true)->orderBy('last_name')->get();
    }

    /**
     * @return HasMany<AppointmentType, $this>
     */
    public function appointmentTypes(): HasMany
    {
        return $this->hasMany(AppointmentType::class);
    }
}
