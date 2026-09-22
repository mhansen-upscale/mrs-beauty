<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Das Erscheinungsbild der **Buchungsseite**.
 *
 * Nicht des Admin-Bereichs: `docs/design/farben.md` schliesst das ausdruecklich
 * aus. Der Admin-Bereich ist ein Arbeitswerkzeug und sieht nach dem Produkt
 * aus, nicht nach dem Kunden.
 *
 * @property string|null $primary_color
 * @property string|null $imprint_url
 * @property string|null $privacy_url
 */
class Branding extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * Die Farbe darf mit Wert ins Protokoll: sie sagt nichts ueber eine
     * Person (Entscheidung C5), und wer sie geaendert hat, gehoert
     * nachlesbar.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['primary_color'];
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Das Logo -- oder null, wenn die Pruefung es beanstandet hat.
     *
     * **Nicht `istFreigegeben()`.** Das Logo laedt eine Praxisinhaberin von
     * ihrer eigenen Marke hoch; es ist kein ungefragt zugesandtes Foto aus
     * einem Chat. Die strenge Regel haette bedeutet, dass ohne angebundenen
     * Scanner niemals ein Logo erscheint -- ueberall steht dann `unscanned`.
     *
     * Was wirklich schuetzt, ist die Beschraenkung auf Rasterbilder: eine
     * SVG-Datei kann ein Skript enthalten, und ausgeliefert von unserer
     * eigenen Adresse waere das ein Skript auf der Buchungsseite. Ein
     * Virenscanner findet so etwas nicht.
     */
    public function logo(): ?Attachment
    {
        $anhang = $this->attachments()->latest('created_at')->first();

        return $anhang instanceof Attachment && ! $anhang->istAbgelehnt() ? $anhang : null;
    }

    /** Fehlt etwas, das auf einer oeffentlichen Seite stehen muss? */
    public function rechtlichVollstaendig(): bool
    {
        return is_string($this->imprint_url) && $this->imprint_url !== ''
            && is_string($this->privacy_url) && $this->privacy_url !== '';
    }
}
