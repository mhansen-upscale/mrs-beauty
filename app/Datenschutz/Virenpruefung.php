<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Models\Attachment;

/**
 * Die Virenpruefung eines Anhangs.
 *
 * Als Schnittstelle, weil der Pruefer Infrastruktur ist und nicht Fachlogik:
 * in der Entwicklung gibt es keinen, im Betrieb einen Dienst. Was beide
 * gemeinsam haben, ist das Ergebnis am Datensatz -- und die Zusage, dass eine
 * ungepruefte Datei nicht ausgeliefert wird.
 */
interface Virenpruefung
{
    /** Setzt scanned_at und scan_result am Anhang. */
    public function pruefe(Attachment $anhang, string $inhalt): void;
}
