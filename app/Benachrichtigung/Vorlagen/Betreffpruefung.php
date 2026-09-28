<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

use App\Models\AppointmentType;
use App\Models\Treatment;

/**
 * Nennt ein Betreff eine Behandlung? (Entscheidung C17)
 *
 * **Der Betreff steht auf dem Sperrbildschirm**, den auch andere sehen
 * (WP-13). "Ihr Botox-Termin am Dienstag" sagt dort, wer sich wo behandeln
 * laesst. Geprueft wird gegen den aktiven Katalog und die Terminarten des
 * geltenden Mandanten -- dieselbe Regel wie App\Werbung\Namenspruefung.
 *
 * **Eine Instanz je Mandant.** Der Katalog wird fuer die Lebensdauer der
 * Instanz gemerkt; ein Arbeiter, der sie ueber zwei Praxen hielte, pruefte
 * die zweite gegen den Katalog der ersten. Deshalb nie als Singleton binden.
 */
final class Betreffpruefung
{
    /** @var list<string>|null */
    private ?array $bezeichnungen = null;

    /** Welche Bezeichnung getroffen hat -- fuer die Meldung am Feld. */
    public function treffer(string $betreff): ?string
    {
        if (trim($betreff) === '') {
            return null;
        }

        foreach ($this->bezeichnungen() as $bezeichnung) {
            if (mb_stripos($betreff, $bezeichnung) !== false) {
                return $bezeichnung;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function bezeichnungen(): array
    {
        if ($this->bezeichnungen !== null) {
            return $this->bezeichnungen;
        }

        /** @var list<string> $terminarten */
        $terminarten = AppointmentType::query()->aktiv()->pluck('name')->map(strval(...))->values()->all();

        // Sehr kurze Bezeichnungen bleiben draussen, wie bei der
        // Namenspruefung: "PRP" traefe sonst jeden dritten Betreff.
        $mindestens = (int) config('mrs.mail.betreff_mindestlaenge');

        return $this->bezeichnungen = array_values(array_unique(array_filter(
            [...Treatment::aktiveNamen(), ...$terminarten],
            fn (string $name): bool => mb_strlen($name) >= $mindestens,
        )));
    }
}
