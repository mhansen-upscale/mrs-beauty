<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Enums\ComplianceCode;

/**
 * Angst erzeugende Darstellung (Paragraf 11 Abs. 1 Nr. 7 HWG).
 *
 * Gelb, nicht rot: ob eine Formulierung Angst erzeugt, haengt am Zusammenhang
 * -- und das kann eine Wortliste nicht entscheiden (Regel 6).
 */
final class Angstwerbung extends Wortregel
{
    protected function muster(): array
    {
        return [
            'bevor es zu spät ist', 'zu spät', 'sonst altern Sie',
            'niemand will', 'peinlich', 'schämen', 'unattraktiv',
            'lassen Sie sich nicht gehen', 'bevor andere es bemerken',
        ];
    }

    protected function code(): ComplianceCode
    {
        return ComplianceCode::FearAdvertising;
    }

    protected function vorschlag(): string
    {
        return 'Sprechen Sie über den Wunsch, nicht über die Angst: „Wenn Sie sich frischer fühlen möchten, '
            .'beraten wir Sie in Ruhe — auch dazu, wann eine Behandlung nicht sinnvoll ist."';
    }
}
