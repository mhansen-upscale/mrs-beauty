<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

use App\Enums\Mailfeld;

/**
 * Die fuenf Felder einer Mail, wie sie geschrieben wurden -- mit
 * Platzhaltern, noch nicht gesetzt.
 */
final class Mailtext
{
    public function __construct(
        public readonly string $betreff,
        public readonly string $anrede = '',
        public readonly string $einleitung = '',
        public readonly string $schluss = '',
        public readonly string $gruss = '',
    ) {}

    public function feld(Mailfeld $feld): string
    {
        return match ($feld) {
            Mailfeld::Betreff => $this->betreff,
            Mailfeld::Anrede => $this->anrede,
            Mailfeld::Einleitung => $this->einleitung,
            Mailfeld::Schluss => $this->schluss,
            Mailfeld::Gruss => $this->gruss,
        };
    }

    public function mitBetreff(string $betreff): self
    {
        return new self($betreff, $this->anrede, $this->einleitung, $this->schluss, $this->gruss);
    }

    /**
     * Aus Formular oder Zeile -- Schluessel sind die Spalten (Mailfeld).
     *
     * @param  array<array-key, mixed>  $werte
     */
    public static function aus(array $werte): self
    {
        $wert = fn (Mailfeld $feld): string => is_string($werte[$feld->value] ?? null)
            ? str_replace("\r\n", "\n", trim($werte[$feld->value]))
            : '';

        return new self(
            $wert(Mailfeld::Betreff),
            $wert(Mailfeld::Anrede),
            $wert(Mailfeld::Einleitung),
            $wert(Mailfeld::Schluss),
            $wert(Mailfeld::Gruss),
        );
    }

    /** @return array<string, string> */
    public function alsFelder(): array
    {
        $felder = [];

        foreach (Mailfeld::cases() as $feld) {
            $felder[$feld->value] = $this->feld($feld);
        }

        return $felder;
    }
}
