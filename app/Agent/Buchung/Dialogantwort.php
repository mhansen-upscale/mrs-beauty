<?php

declare(strict_types=1);

namespace App\Agent\Buchung;

use App\Enums\GuardrailHit;

/**
 * Was der Buchungsdialog aus einer Nachricht gemacht hat.
 *
 * Entweder ein Satz, der hinausgehen darf -- oder eine Uebergabe mit Grund.
 * Ein drittes gibt es nicht: ein Automat, der schweigt, ohne zu uebergeben,
 * laesst jemanden auf eine Antwort warten, die nie kommt.
 */
final class Dialogantwort
{
    private function __construct(
        public readonly ?string $text,
        public readonly ?GuardrailHit $uebergabe,
        public readonly bool $gebucht,
    ) {}

    public static function sagt(string $text, bool $gebucht = false): self
    {
        return new self($text, null, $gebucht);
    }

    public static function uebergibt(GuardrailHit $grund, ?string $text = null): self
    {
        return new self($text, $grund, false);
    }
}
