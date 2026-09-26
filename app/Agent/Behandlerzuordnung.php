<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\Practitioner;
use Illuminate\Support\Collection;

/**
 * Welche Behandlerin gemeint ist (docs/fachlogik/agent.md, Schritt 6).
 *
 * **Das Modell schlaegt vor, der Bestand entscheidet** -- wie bei Behandlung
 * und Standort (Entscheidung D2). Wer "Frau Sauer" schreibt, meint "Dr. Lena
 * Sauer"; wer "Dr. Unbekannt" schreibt, meint niemanden, den es gibt.
 *
 * **Eindeutig oder gar nicht.** Zwei Behandler mit demselben Nachnamen sind
 * kein Grund zu raten, sondern einer, nachzufragen.
 */
final class Behandlerzuordnung
{
    /** Anrede und Titel -- sie unterscheiden niemanden. */
    private const OHNE = ['dr', 'prof', 'med', 'frau', 'herr', 'fr', 'hr', 'bei', 'dem', 'der'];

    /**
     * Aus dem Namen, den das Modell genannt hat.
     *
     * @param  Collection<int, Practitioner>|null  $kandidaten
     */
    public function ausName(string $name, ?Collection $kandidaten = null): ?Practitioner
    {
        $kandidaten ??= $this->aktive();
        $gesucht = $this->normalisiere($name);

        if ($gesucht === '') {
            return null;
        }

        $genau = $kandidaten->filter(fn (Practitioner $behandler): bool => $this->normalisiere($behandler->name()) === $gesucht);

        if ($genau->count() === 1) {
            return $genau->first();
        }

        return $this->ausText($name, $kandidaten);
    }

    /**
     * Aus einer freien Antwort -- "Dann bei Frau Sauer".
     *
     * @param  Collection<int, Practitioner>|null  $kandidaten
     */
    public function ausText(string $text, ?Collection $kandidaten = null): ?Practitioner
    {
        $kandidaten ??= $this->aktive();
        $woerter = explode(' ', $this->normalisiere($text));

        $treffer = $kandidaten->filter(
            fn (Practitioner $behandler): bool => in_array(mb_strtolower($behandler->last_name), $woerter, true),
        );

        return $treffer->count() === 1 ? $treffer->first() : null;
    }

    /**
     * @return Collection<int, Practitioner>
     */
    private function aktive(): Collection
    {
        return Practitioner::query()->where('is_active', true)->get();
    }

    private function normalisiere(string $text): string
    {
        $klein = mb_strtolower($text);
        $ohneZeichen = (string) preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $klein);
        $woerter = preg_split('/\s+/', trim($ohneZeichen)) ?: [];

        return implode(' ', array_values(array_filter(
            $woerter,
            fn (string $wort): bool => $wort !== '' && ! in_array($wort, self::OHNE, true),
        )));
    }
}
