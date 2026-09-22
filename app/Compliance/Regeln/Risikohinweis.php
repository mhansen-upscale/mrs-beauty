<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Compliance\Befund;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Regel;
use App\Enums\Ampel;
use App\Enums\ComplianceCode;

/**
 * Der Pflichthinweis auf Risiken
 * (Paragraf 11 Abs. 1 S. 3 Nr. 2 HWG).
 *
 * Wer eine operative plastisch-chirurgische Massnahme bewirbt, muss auf
 * Risiken hinweisen. Ob eine konkrete Anzeige darunter faellt, entscheidet
 * der Zusammenhang -- deshalb gelb und nicht rot.
 */
final class Risikohinweis implements Regel
{
    /** Wendungen, die eine hinweispflichtige Massnahme nahelegen. */
    private const EINGRIFFE = [
        'Operation', 'operativ', 'OP', 'Eingriff', 'chirurgisch',
        'Straffung', 'Lidstraffung', 'Facelift', 'Fettabsaugung', 'Implantat',
        'Unterspritzung', 'Injektion', 'Botulinum', 'Botox', 'Hyaluron',
    ];

    /** Wendungen, die den Hinweis erkennbar machen. */
    private const HINWEISE = [
        'Risiken', 'Risiko', 'Nebenwirkungen', 'Aufklärung', 'Beratungsgespräch erforderlich',
    ];

    public function pruefe(Pruefgegenstand $gegenstand): array
    {
        $text = $gegenstand->gesamttext();

        if (! $this->enthaelt($text, self::EINGRIFFE)) {
            return [];
        }

        if ($this->enthaelt($text, self::HINWEISE)) {
            return [];
        }

        return [new Befund(
            code: ComplianceCode::MissingRiskNotice,
            ampel: Ampel::Gelb,
            stelle: null,
            vorschlag: 'Ergänzen Sie einen Hinweis auf Risiken und die Notwendigkeit eines '
                .'Beratungsgesprächs — etwa: „Wie bei jedem Eingriff gibt es Risiken. Wir besprechen sie '
                .'vorab persönlich mit Ihnen."',
        )];
    }

    /**
     * @param  list<string>  $wendungen
     */
    private function enthaelt(string $text, array $wendungen): bool
    {
        foreach ($wendungen as $wendung) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($wendung, '/').'/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
