<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Enums\ComplianceCode;

/**
 * Erfolgsversprechen und Garantien (Paragraf 3 HWG).
 *
 * Eine Werbung darf nicht den Eindruck erwecken, ein Erfolg sei sicher. Genau
 * das tun die Wendungen hier -- und sie sind in dieser Branche die
 * gaengigsten.
 */
final class Heilungsversprechen extends Wortregel
{
    protected function muster(): array
    {
        return [
            'garantiert', 'Garantie', 'garantieren',
            'sicherer Erfolg', 'sichere Ergebnisse', 'garantierter Erfolg',
            '100 % Erfolg', '100% Erfolg',
            'wirkt immer', 'hilft immer', 'immer erfolgreich',
            'dauerhafte Ergebnisse', 'für immer faltenfrei', 'faltenfrei für immer',
        ];
    }

    protected function code(): ComplianceCode
    {
        return ComplianceCode::HealingPromise;
    }

    protected function vorschlag(): string
    {
        return 'Beschreiben Sie, was die Behandlung tut, statt was sie verspricht: '
            .'„Wir glätten Mimikfalten an Stirn und Augen. Wie lange das anhält, ist individuell."';
    }
}
