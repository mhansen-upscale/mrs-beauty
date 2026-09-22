<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Models\Attachment;
use Carbon\CarbonImmutable;

/**
 * Die Vorgabe, solange kein Pruefdienst angebunden ist.
 *
 * **Sie gibt nichts frei** (WP-33). Bis dahin schrieb sie `clean`, obwohl
 * niemand hingesehen hatte -- der Kommentar behauptete `unscanned`, der Code
 * tat etwas anderes, und damit war die Luecke genau die, vor der Regel 4
 * warnt: eine, die niemand bemerkt.
 *
 * Jetzt steht `unscanned` da, und `Attachment::istFreigegeben()` liefert
 * nichts aus, was so vermerkt ist. In der Entwicklung heisst das: Anhaenge
 * werden aufgenommen, aber nicht angezeigt. Das ist unbequem und ehrlich --
 * und es faellt sofort auf, wenn im Betrieb niemand einen Pruefer angebunden
 * hat.
 */
final class KeineVirenpruefung implements Virenpruefung
{
    public function pruefe(Attachment $anhang, string $inhalt): void
    {
        $anhang->scanned_at = CarbonImmutable::now();
        $anhang->scan_result = Scanergebnis::Unscanned->value;
        $anhang->save();
    }
}
