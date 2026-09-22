<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\Ampel;
use App\Enums\Vorschlagsstatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Ein Anzeigenentwurf.
 *
 * **Kein Vorschlag erreicht die Veroeffentlichung ohne vorherige Pruefung**
 * (WP-30, Abnahmekriterium 5). Jeder Entwurf traegt deshalb ein
 * Pruefergebnis; einen ungeprueften gibt es nicht.
 *
 * @property CarbonImmutable $week
 * @property string $headline
 * @property string $body
 * @property string|null $description
 * @property string|null $cta
 * @property Vorschlagsstatus $status
 * @property string|null $model
 * @property string|null $image_prompt
 * @property string|null $image_brief
 * @property string|null $image_model
 * @property CarbonImmutable|null $image_requested_at
 * @property string|null $image_error
 */
class AdSuggestion extends TenantModel implements HasPersonalData
{
    use Auditable;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week' => 'immutable_date',
            'status' => Vorschlagsstatus::class,
            'headline' => Encrypted::class,
            'body' => Encrypted::class,
            'description' => Encrypted::class,
            'image_prompt' => Encrypted::class,
            'image_brief' => Encrypted::class,
            'image_requested_at' => 'immutable_datetime',
        ];
    }

    /**
     * Anzeigentexte nennen die beworbene Leistung -- das ist erlaubt (C9) und
     * damit doch eine Behandlungsbezeichnung. Sie faellt unter die Maskierung
     * (C4).
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['headline', 'body', 'description', 'image_prompt', 'image_brief'];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status'];
    }

    /** @return MorphOne<ComplianceCheck, $this> */
    public function pruefung(): MorphOne
    {
        // Der Schluessel als zweites Merkmal: zwei Pruefungen desselben
        // Entwurfs koennen denselben Zeitstempel tragen, und dann waere die
        // "juengste" beliebig.
        return $this->morphOne(ComplianceCheck::class, 'checkable')
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], 'max');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Die Grafik zu diesem Entwurf.
     *
     * **Wurde die Beziehung geladen, wird sie benutzt.** Die Galerie zeigt
     * dreissig Entwuerfe; eine eigene Abfrage je Kachel waere dort eine
     * Abfrage zu viel -- und zwar dreissigmal.
     */
    public function bild(): ?Attachment
    {
        $anhang = $this->relationLoaded('attachments')
            ? $this->attachments->sortByDesc('created_at')->first()
            : $this->attachments()->latest('created_at')->first();

        return $anhang instanceof Attachment && ! $anhang->istAbgelehnt() ? $anhang : null;
    }

    public function ampel(): ?Ampel
    {
        return $this->pruefergebnis()?->result;
    }

    private function pruefergebnis(): ?ComplianceCheck
    {
        return $this->relationLoaded('pruefung') ? $this->pruefung : $this->pruefung()->first();
    }

    /**
     * Darf dieser Entwurf freigegeben werden?
     *
     * Gruen -- oder rot mit einer Uebersteuerung, die jemand begruendet hat
     * (Entscheidung C3). **Gelb allein genuegt nicht**: es heisst, jemand
     * muss hinsehen, und das ist nicht dasselbe wie hingesehen haben.
     */
    public function darfFreigegebenWerden(): bool
    {
        return $this->pruefergebnis()?->gibtFrei() ?? false;
    }
}
