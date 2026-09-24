<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

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
    /**
     * Wie lang ein Vermerk hoechstens wird.
     *
     * **Die Spalte ist `text`, die Grenze steht hier.** Am 24.09.2026 kippte
     * eine Meta-Meldung von 290 Zeichen den Auftrag, weil `ads.sync_error`
     * 255 fasste -- und mit dem Auftrag war der Grund fort. Eine breitere
     * Spalte allein verschiebt diesen Fehler nur nach hinten: das Festhalten
     * eines Fehlschlags darf nie selbst fehlschlagen koennen.
     *
     * Tausend Zeichen sind mehr als jede Meldung, die Meta bisher geschickt
     * hat, und wenig genug, um in einer Kachel zu stehen.
     */
    private const VERMERK_MAX = 1000;

    /** Woran man sieht, dass etwas abgeschnitten wurde. */
    private const VERMERK_ENDE = ' …';

    /**
     * Der Grund, warum eine Uebertragung haengt -- gekappt statt geworfen.
     *
     * Die Obergrenze gilt einschliesslich des Endezeichens: `Str::limit`
     * haengt es an, statt es einzurechnen.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function syncError(): Attribute
    {
        $ende = self::VERMERK_ENDE;

        return Attribute::set(fn (?string $wert): ?string => $wert === null
            ? null
            : Str::limit($wert, self::VERMERK_MAX - mb_strlen($ende), $ende));
    }

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
