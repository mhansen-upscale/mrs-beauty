<?php

declare(strict_types=1);

namespace Tests\Feature\Anzeigen;

use App\Anzeigen\Bild;
use App\Anzeigen\Bildmodell;
use App\Anzeigen\Bildsatz;
use Closure;
use RuntimeException;

/**
 * Ein Bildmodell, das nicht zeichnet.
 *
 * **Eine Klasse statt einer anonymen je Test.** Seit WP-31b nimmt das
 * Bildmodell je Format einen Auftrag; zehn gleichlautende anonyme Klassen
 * haetten zehnmal dieselbe Schleife getragen.
 *
 * Jeder Auftrag landet im Mitschnitt -- was hinausging, laesst sich danach
 * nachlesen.
 */
final class Bildattrappe implements Bildmodell
{
    /**
     * @param  Closure(string): (Bild|string)  $je  je Format ein Bild -- oder
     *                                              der Grund, warum keines kam
     */
    private function __construct(private readonly Closure $je) {}

    /** Liefert zu jedem Format ein Bild. */
    public static function liefert(): self
    {
        return new self(fn (): Bild => self::bild());
    }

    /** Liefert jedes Format ausser diesem einen. */
    public static function ohne(string $format, string $grund): self
    {
        return new self(fn (string $gefragt): Bild|string => $gefragt === $format ? $grund : self::bild());
    }

    /** Liefert fuer kein Format ein Bild. */
    public static function scheitert(string $grund): self
    {
        return new self(fn (): string => $grund);
    }

    /** Darf gar nicht erst gefragt werden. */
    public static function verbietet(string $warum): self
    {
        return new self(fn (): never => throw new RuntimeException($warum));
    }

    public function erzeuge(array $auftraege): Bildsatz
    {
        Mitschnitt::$bildauftraege = $auftraege;

        $bilder = [];
        $fehler = [];

        foreach ($auftraege as $format => $auftrag) {
            $ergebnis = ($this->je)($format);

            if ($ergebnis instanceof Bild) {
                $bilder[$format] = $ergebnis;
            } else {
                $fehler[$format] = $ergebnis;
            }
        }

        return new Bildsatz($bilder, $fehler);
    }

    public function angebunden(): bool
    {
        return true;
    }

    private static function bild(): Bild
    {
        return new Bild('bilddaten', 'image/png', 'testmodell');
    }
}
