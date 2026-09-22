<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Compliance\Befund;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Regel;
use App\Enums\Ampel;
use App\Enums\ComplianceCode;

/**
 * Vorher-Nachher-Darstellung
 * (Paragraf 11 Abs. 1 S. 3 Nr. 1 HWG, BGH I ZR 170/24 vom 31.07.2025).
 *
 * **Das Bildverbot gilt seit dem Urteil auch fuer minimalinvasive Eingriffe**
 * -- Botox und Hyaluron eingeschlossen, nicht nur klassische Operationen.
 * Verstoesse koennen mit bis zu 50.000 Euro geahndet werden. Die Regel darf
 * deshalb nicht an der Art des Eingriffs haengen.
 *
 * **Und die Maschine entscheidet nicht ueber Bilder.** Geteilte Bilder,
 * Pfeile, Beschriftungen, Bildpaare -- nichts davon ist aus einem Dateinamen
 * zu erkennen. Ein Bild ohne menschliche Bestaetigung bekommt deshalb **nie
 * gruen**, sondern gelb: jemand muss hinsehen (Regel 6). Wer das fuer streng
 * haelt, rechne 50.000 Euro gegen einen Klick.
 */
final class VorherNachher implements Regel
{
    /** @var list<string> */
    private const WENDUNGEN = [
        'vorher', 'nachher', 'vorher-nachher', 'vorher nachher',
        'before', 'after', 'before-after', 'b\/a',
        'Behandlungsergebnis', 'Behandlungsergebnisse', 'Ergebnisse unserer Patientinnen',
        'davor', 'danach im Vergleich',
    ];

    public function pruefe(Pruefgegenstand $gegenstand): array
    {
        $text = $gegenstand->gesamttext();

        foreach (self::WENDUNGEN as $wendung) {
            $muster = '/(?<![\p{L}\p{N}])'.preg_quote($wendung, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($muster, $text, $treffer) === 1) {
                return [new Befund(
                    code: ComplianceCode::BeforeAfter,
                    ampel: Ampel::Rot,
                    stelle: $treffer[0],
                    vorschlag: $this->vorschlag(),
                )];
            }
        }

        if ($gegenstand->hatBild) {
            return [new Befund(
                code: ComplianceCode::BeforeAfter,
                ampel: Ampel::Gelb,
                stelle: null,
                vorschlag: 'Bitte bestätigen Sie, dass das Bild kein Behandlungsergebnis zeigt — '
                    .'weder einzeln noch im Vergleich. Das können wir nicht automatisch prüfen.',
            )];
        }

        return [];
    }

    private function vorschlag(): string
    {
        return 'Zeigen Sie stattdessen, was zulässig ist: die Ärztin, den Ablauf der Behandlung, '
            .'die Räume, Preis- und Risikotransparenz. Diese Formate wirken und sind erlaubt.';
    }
}
