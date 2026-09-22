<?php

declare(strict_types=1);

namespace App\Marke;

use App\Models\BrandGuide;
use App\Models\BrandReference;

/**
 * Was noch fehlt, damit ein Vorschlag mehr wird als eine Allerweltsanzeige.
 *
 * **Er haengt an den Feldern, die WP-31 wirklich braucht**, nicht an allen.
 * Ein Reifegrad, der immer "vollstaendig" sagt, ist wertlos -- und einer, der
 * nie vollstaendig wird, auch.
 */
final class Reifegrad
{
    /**
     * @return array{anteil: int, fehlt: list<string>}
     */
    public function fuer(?BrandGuide $profil): array
    {
        $punkte = [
            'Tonalität' => $profil?->tone !== null,
            'Ansprache' => $profil?->address_form !== null,
            'Zielgruppe' => $this->gefuellt($profil?->audience),
            'Positionierung' => $this->gefuellt($profil?->positioning),
            'Referenzmaterial' => BrandReference::query()->exists(),
        ];

        return [
            'anteil' => (int) round(count(array_filter($punkte)) / count($punkte) * 100),
            'fehlt' => array_keys(array_filter($punkte, fn (bool $steht): bool => ! $steht)),
        ];
    }

    private function gefuellt(?string $wert): bool
    {
        return $wert !== null && trim($wert) !== '';
    }
}
