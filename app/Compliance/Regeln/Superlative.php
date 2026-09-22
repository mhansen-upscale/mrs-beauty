<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Enums\ComplianceCode;

/**
 * Unbelegte Superlative und Spitzenstellungsbehauptungen (UWG).
 *
 * Gelb: belegt ist eine Spitzenstellung zulaessig. Die Maschine kann nicht
 * wissen, ob sie belegt ist -- ein Mensch schon.
 */
final class Superlative extends Wortregel
{
    protected function muster(): array
    {
        return [
            'beste', 'bester', 'bestes', 'die Nummer 1', 'Nummer eins',
            'führend', 'marktführend', 'einzigartig', 'weltweit einzigartig',
            'modernste', 'fortschrittlichste', 'unübertroffen',
        ];
    }

    protected function code(): ComplianceCode
    {
        return ComplianceCode::Superlatives;
    }

    protected function vorschlag(): string
    {
        return 'Belegen Sie die Aussage oder ersetzen Sie sie durch etwas Nachprüfbares: '
            .'Jahre Erfahrung, Zahl der Behandlungen, Fachgebiet der Ärztin.';
    }
}
