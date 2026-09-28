<?php

declare(strict_types=1);

namespace App\Backoffice;

/**
 * Ein Monat der Finanzuebersicht: die Praxiszeilen und ihre Summe (WP-34d).
 *
 * **Die Summe ist die Summe der Zeilen**, gerechnet ueber Praxisergebnis::plus
 * und nicht in einer zweiten Abfrage -- sonst stimmten Tabelle und Kopfzeile
 * irgendwann nicht mehr ueberein (AK 13).
 */
final class Finanzmonat
{
    /**
     * @param  list<Praxisergebnis>  $praxen
     */
    public function __construct(
        public readonly string $monat,
        public readonly array $praxen,
        public readonly ?int $fixkosten,
    ) {}

    public function praxis(?string $uuid): ?Praxisergebnis
    {
        foreach ($this->praxen as $zeile) {
            if ($zeile->uuid === $uuid) {
                return $zeile;
            }
        }

        return null;
    }

    public function summe(): Praxisergebnis
    {
        return array_reduce(
            $this->praxen,
            fn (Praxisergebnis $summe, Praxisergebnis $zeile): Praxisergebnis => $summe->plus($zeile),
            new Praxisergebnis(uuid: '', name: 'Summe', zugang: null),
        );
    }

    /** Einnahmen minus variable Kosten, ueber alle Praxen. */
    public function rohertrag(): int
    {
        return $this->summe()->deckungsbeitrag();
    }

    /** Ohne hinterlegte Fixkosten gibt es kein Ergebnis -- keine Null (B19). */
    public function ergebnis(): ?int
    {
        return $this->fixkosten === null ? null : $this->rohertrag() - $this->fixkosten;
    }

    public function marge(): ?float
    {
        $einnahmen = $this->summe()->einnahmen();

        return $einnahmen > 0 ? round($this->rohertrag() / $einnahmen, 4) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summe = $this->summe();

        return [
            'monat' => $this->monat,
            'daten' => true,
            'einnahmenCent' => $summe->einnahmen(),
            'kostenCent' => $summe->kosten(),
            'rohertragCent' => $summe->deckungsbeitrag(),
            'ergebnisCent' => $this->ergebnis(),
            'unvollstaendig' => $summe->unvollstaendig(),
        ];
    }

    /**
     * Ein Monat ohne Abschluss: **keine Daten**, nicht null (AK 15).
     *
     * @return array<string, mixed>
     */
    public static function ohneDaten(string $monat): array
    {
        return [
            'monat' => $monat,
            'daten' => false,
            'einnahmenCent' => null,
            'kostenCent' => null,
            'rohertragCent' => null,
            'ergebnisCent' => null,
            'unvollstaendig' => false,
        ];
    }
}
