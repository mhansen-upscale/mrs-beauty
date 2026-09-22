<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Ein Modell, das zu verschluesselten Feldern blinde Indizes nachfuehrt.
 *
 * Existiert, damit der saving-Haken in HasBlindIndexes einen Typ hat, auf den
 * er sich berufen kann -- ein Trait taugt nicht als Typangabe.
 */
interface UsesBlindIndexes
{
    /**
     * Feldname => Spalte des blinden Index.
     *
     * @return array<string, string>
     */
    public function blindIndexes(): array;
}
