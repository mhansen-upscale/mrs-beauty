<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Compliance\Regeln\Angstwerbung;
use App\Compliance\Regeln\Dankschreiben;
use App\Compliance\Regeln\Heilungsversprechen;
use App\Compliance\Regeln\Markenverstoss;
use App\Compliance\Regeln\RisikofreieAussage;
use App\Compliance\Regeln\Risikohinweis;
use App\Compliance\Regeln\Superlative;
use App\Compliance\Regeln\VorherNachher;
use App\Marke\Begriffspruefung;

/**
 * Die Regeln einer Regelwerksfassung.
 *
 * Eine Klasse je Regel: so traegt jede ihre Fundstelle, ihren Grund und ihren
 * Formulierungsvorschlag bei sich, und eine neue aendert nichts an den
 * bestehenden.
 *
 * **Der Startregelsatz steht in `specs/WP-30-hwg-compliance.md`.** Was hier
 * fehlt, wird nicht geprueft -- und das faellt erst auf, wenn jemand danach
 * fragt.
 */
final class Regelwerk
{
    public function __construct(private readonly Begriffspruefung $begriffe) {}

    /**
     * @return list<Regel>
     */
    public function regeln(): array
    {
        return [
            new VorherNachher,
            new Heilungsversprechen,
            new RisikofreieAussage,
            new Dankschreiben,
            new Angstwerbung,
            new Superlative,
            new Risikohinweis,
            new Markenverstoss($this->begriffe),
        ];
    }
}
