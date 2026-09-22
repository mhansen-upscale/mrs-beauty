<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Compliance\Befund;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Regel;
use App\Enums\ComplianceCode;

/**
 * Regeln, die an Formulierungen haengen.
 *
 * **Mit Wortgrenzen**, wie die Begriffspruefung in WP-29: ohne sie traefe
 * "heilt" jedes "geheilt", "Heiltherapie" und "unheilbar" -- und ein Hinweis,
 * der staendig erscheint, wird abgeschaltet.
 */
abstract class Wortregel implements Regel
{
    /**
     * @return list<string>
     */
    abstract protected function muster(): array;

    abstract protected function code(): ComplianceCode;

    abstract protected function vorschlag(): string;

    public function pruefe(Pruefgegenstand $gegenstand): array
    {
        $text = $gegenstand->gesamttext();
        $befunde = [];

        foreach ($this->muster() as $wendung) {
            if (preg_match($this->alsMuster($wendung), $text, $treffer) === 1) {
                $befunde[] = new Befund(
                    code: $this->code(),
                    ampel: $this->code()->ampel(),
                    stelle: $treffer[0],
                    vorschlag: $this->vorschlag(),
                );

                // Ein Befund je Regel genuegt: die Praxis muss die Aussage
                // aendern, nicht jede ihrer Vorkommen einzeln zaehlen.
                break;
            }
        }

        return $befunde;
    }

    protected function alsMuster(string $wendung): string
    {
        return '/(?<![\p{L}\p{N}])'.str_replace('\ ', '\s+', preg_quote($wendung, '/')).'(?![\p{L}\p{N}])/iu';
    }
}
