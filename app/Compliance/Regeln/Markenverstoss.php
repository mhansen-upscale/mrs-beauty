<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Compliance\Befund;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Regel;
use App\Enums\Ampel;
use App\Enums\ComplianceCode;
use App\Marke\Begriffspruefung;

/**
 * Verstoss gegen die verbotenen Begriffe der Praxis (WP-29).
 *
 * **Dieselbe Liste, zwei Pruefungen** -- so steht es im Briefing zu WP-29:
 * der Markenhinweis dort, der Befund mit Fundstelle hier. Deshalb traegt jeder
 * Begriff einen Ersatz, und der wird hier als Vorschlag weitergereicht.
 *
 * Gelb: eine Geschmacksfrage der Praxis ist kein Rechtsverstoss.
 */
final class Markenverstoss implements Regel
{
    public function __construct(private readonly Begriffspruefung $begriffe) {}

    public function pruefe(Pruefgegenstand $gegenstand): array
    {
        $befunde = [];

        foreach ($this->begriffe->pruefe($gegenstand->gesamttext()) as $treffer) {
            $befunde[] = new Befund(
                code: ComplianceCode::BrandViolation,
                ampel: Ampel::Gelb,
                stelle: $treffer->begriff,
                vorschlag: $treffer->ersatz === null
                    ? 'Dieser Begriff steht auf Ihrer Vermeidungsliste.'
                    : sprintf('Statt „%s" lieber „%s".%s', $treffer->begriff, $treffer->ersatz,
                        $treffer->begruendung === null ? '' : ' ('.$treffer->begruendung.')'),
            );
        }

        return $befunde;
    }
}
