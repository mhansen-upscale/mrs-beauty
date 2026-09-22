<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Farbrechnung: sRGB, OKLCH, HSL und WCAG-Kontrast.
 *
 * **Warum OKLCH und nicht HSL.** Eine Abstufung ueber HSL bleicht gesaettigte
 * Toene in den mittleren Stufen aus: HSL kennt keine wahrgenommene
 * Helligkeit, sondern rechnet auf den Rohkanaelen. OKLab ist genau dafuer
 * gebaut -- Farbton und Chroma bleiben, waehrend die Helligkeit wandert.
 *
 * Siehe docs/design/farben.md, Abschnitt "Mandantenfarbe auf der
 * Buchungsseite".
 */
final class Farbe
{
    /**
     * @param  float  $r  0..1
     * @param  float  $g  0..1
     * @param  float  $b  0..1
     */
    private function __construct(
        public readonly float $r,
        public readonly float $g,
        public readonly float $b,
    ) {}

    public static function ausHex(string $hex): self
    {
        $ziffern = ltrim(trim($hex), '#');

        if (strlen($ziffern) === 3) {
            $ziffern = $ziffern[0].$ziffern[0].$ziffern[1].$ziffern[1].$ziffern[2].$ziffern[2];
        }

        if (strlen($ziffern) !== 6 || ! ctype_xdigit($ziffern)) {
            throw new InvalidArgumentException("Keine gueltige Farbe: {$hex}");
        }

        return new self(
            hexdec(substr($ziffern, 0, 2)) / 255,
            hexdec(substr($ziffern, 2, 2)) / 255,
            hexdec(substr($ziffern, 4, 2)) / 255,
        );
    }

    /**
     * @param  float  $lightness  0..1
     * @param  float  $chroma  0..0.4 in der Praxis
     * @param  float  $hue  Bogenmass
     */
    public static function ausOklch(float $lightness, float $chroma, float $hue): self
    {
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);

        $lStrich = $lightness + 0.3963377774 * $a + 0.2158037573 * $b;
        $mStrich = $lightness - 0.1055613458 * $a - 0.0638541728 * $b;
        $sStrich = $lightness - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $lStrich ** 3;
        $m = $mStrich ** 3;
        $s = $sStrich ** 3;

        return new self(
            self::ausLinear(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            self::ausLinear(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            self::ausLinear(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float} Helligkeit, Chroma, Farbton (Bogenmass)
     */
    public function oklch(): array
    {
        $r = self::inLinear($this->r);
        $g = self::inLinear($this->g);
        $b = self::inLinear($this->b);

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $helligkeit = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$helligkeit, sqrt($a ** 2 + $bb ** 2), atan2($bb, $a)];
    }

    /**
     * Die Form, in der die Tokens stehen: "178 50% 24%".
     *
     * Ohne Klammern und ohne Kommas, damit sich die Variable in Tailwind mit
     * einem Alphawert kombinieren laesst: hsl(var(--primary) / 0.1).
     */
    public function alsHslToken(): string
    {
        $max = max($this->r, $this->g, $this->b);
        $min = min($this->r, $this->g, $this->b);
        $spanne = $max - $min;

        $helligkeit = ($max + $min) / 2;

        if ($spanne === 0.0) {
            return '0 0% '.round($helligkeit * 100).'%';
        }

        $saettigung = $helligkeit > 0.5
            ? $spanne / (2 - $max - $min)
            : $spanne / ($max + $min);

        $farbton = match (true) {
            $max === $this->r => fmod(($this->g - $this->b) / $spanne, 6),
            $max === $this->g => ($this->b - $this->r) / $spanne + 2,
            default => ($this->r - $this->g) / $spanne + 4,
        } * 60;

        if ($farbton < 0) {
            $farbton += 360;
        }

        return round($farbton).' '.round($saettigung * 100).'% '.round($helligkeit * 100).'%';
    }

    /**
     * Die Gegenrichtung zu alsHslToken().
     *
     * Gebraucht, weil der Token **gerundet** ist: was im Browser landet, ist
     * nicht ganz die Farbe, die gerechnet wurde. Wer den Kontrast auf der
     * ungerundeten Farbe prueft, prueft etwas anderes als das, was der Mensch
     * vor sich hat.
     */
    public static function ausHslToken(string $token): self
    {
        if (preg_match('/^(-?\d+(?:\.\d+)?) (\d+(?:\.\d+)?)% (\d+(?:\.\d+)?)%$/', trim($token), $treffer) !== 1) {
            throw new InvalidArgumentException("Kein gueltiger HSL-Token: {$token}");
        }

        $farbton = (float) $treffer[1];
        $saettigung = (float) $treffer[2];
        $helligkeit = (float) $treffer[3];

        $saettigung /= 100;
        $helligkeit /= 100;

        $c = (1 - abs(2 * $helligkeit - 1)) * $saettigung;
        $x = $c * (1 - abs(fmod($farbton / 60, 2) - 1));
        $m = $helligkeit - $c / 2;

        [$r, $g, $b] = match (true) {
            $farbton < 60 => [$c, $x, 0.0],
            $farbton < 120 => [$x, $c, 0.0],
            $farbton < 180 => [0.0, $c, $x],
            $farbton < 240 => [0.0, $x, $c],
            $farbton < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return new self($r + $m, $g + $m, $b + $m);
    }

    /** Relative Leuchtdichte nach WCAG 2.1. */
    public function leuchtdichte(): float
    {
        return 0.2126 * self::inLinear($this->r)
            + 0.7152 * self::inLinear($this->g)
            + 0.0722 * self::inLinear($this->b);
    }

    /** Kontrastverhaeltnis nach WCAG 2.1, zwischen 1 und 21. */
    public function kontrastZu(self $andere): float
    {
        $eins = $this->leuchtdichte();
        $zwei = $andere->leuchtdichte();

        return ($eins > $zwei ? $eins + 0.05 : $zwei + 0.05)
            / ($eins > $zwei ? $zwei + 0.05 : $eins + 0.05);
    }

    public static function weiss(): self
    {
        return new self(1.0, 1.0, 1.0);
    }

    private static function inLinear(float $kanal): float
    {
        return $kanal <= 0.04045 ? $kanal / 12.92 : (($kanal + 0.055) / 1.055) ** 2.4;
    }

    private static function ausLinear(float $kanal): float
    {
        $wert = $kanal <= 0.0031308
            ? $kanal * 12.92
            : 1.055 * $kanal ** (1 / 2.4) - 0.055;

        return max(0.0, min(1.0, $wert));
    }
}
