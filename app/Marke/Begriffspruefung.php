<?php

declare(strict_types=1);

namespace App\Marke;

use App\Enums\BrandTermKind;
use App\Models\BrandTerm;

/**
 * Prueft einen Text gegen die verbotenen Begriffe der Praxis.
 *
 * **Dieselbe Liste speist zwei Pruefungen**: den Markenhinweis hier und
 * `brand_violation` im HWG-Regelwerk (WP-30). Deshalb liefert ein Treffer
 * nicht nur den Begriff, sondern auch Ersatz und Begruendung -- ein Befund
 * ohne Ausweg ist ein Vorwurf.
 */
final class Begriffspruefung
{
    /** @var list<BrandTerm>|null */
    private ?array $verboten = null;

    /**
     * @return list<Begriffstreffer>
     */
    public function pruefe(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $treffer = [];

        foreach ($this->verbotene() as $begriff) {
            if ($this->kommtVor($text, $begriff->term)) {
                $treffer[] = new Begriffstreffer(
                    begriff: $begriff->term,
                    ersatz: $begriff->replacement,
                    begruendung: $begriff->reason,
                );
            }
        }

        return $treffer;
    }

    /**
     * Die verbotenen Begriffe -- die Schnittstelle, die WP-30 nutzt.
     *
     * @return list<string>
     */
    public function verboteneBegriffe(): array
    {
        return array_map(fn (BrandTerm $begriff): string => $begriff->term, $this->verbotene());
    }

    /**
     * Ohne Ruecksicht auf Gross- und Kleinschreibung, aber **mit
     * Wortgrenzen**.
     *
     * Ohne sie traefe ein verbotenes "rein" jedes "reine Haut", jedes
     * "Reinigung" und jedes "hereinkommen" -- und ein Hinweis, der staendig
     * erscheint, wird abgeschaltet.
     *
     * Ein Begriff aus mehreren Woertern wird als Ganzes gesucht.
     */
    private function kommtVor(string $text, string $begriff): bool
    {
        $muster = '/(?<![\p{L}\p{N}])'.preg_quote(trim($begriff), '/').'(?![\p{L}\p{N}])/iu';

        return preg_match($muster, $text) === 1;
    }

    /**
     * @return list<BrandTerm>
     */
    private function verbotene(): array
    {
        return $this->verboten ??= array_values(BrandTerm::query()
            ->art(BrandTermKind::Verboten)
            ->orderBy('term')
            ->get()
            ->all());
    }
}
