<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Was Kampagne, Anzeigengruppe und Anzeige gemeinsam haben.
 *
 * **Was bei Meta verschwindet, wird markiert, nicht geloescht.** Eine
 * geloeschte Kampagne bleibt in Auswertungen und ab WP-32 in der Attribution
 * sichtbar -- wer sie entfernt, reisst die Verbindung zwischen einem Termin
 * und der Anzeige, die ihn gebracht hat.
 */
trait GehoertZurWerbestruktur
{
    public function istVerschwunden(): bool
    {
        return $this->vanished_at !== null;
    }

    public function markiereVerschwunden(?CarbonImmutable $jetzt = null): void
    {
        if ($this->vanished_at !== null) {
            return;
        }

        $this->vanished_at = $jetzt ?? CarbonImmutable::now();
        $this->save();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVorhanden(Builder $query): Builder
    {
        return $query->whereNull('vanished_at');
    }
}
