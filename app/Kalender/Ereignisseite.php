<?php

declare(strict_types=1);

namespace App\Kalender;

/**
 * Das Ergebnis eines Abrufs: alle Seiten zusammengefasst, plus der neue
 * Delta-Zeiger.
 *
 * Was der Zeiger ist, bleibt Sache des Anbieters -- bei Google eine
 * Zeichenkette (`nextSyncToken`), bei Graph eine vollstaendige Adresse
 * (`@odata.deltaLink`). Fuer alles davor ist es dasselbe: etwas, das man
 * aufhebt und beim naechsten Mal mitgibt.
 *
 * Er steht nur auf der **letzten** Seite. Wer ihn von der ersten nimmt,
 * verliert alles, was danach kam.
 */
final class Ereignisseite
{
    /**
     * @param  list<Ereignis>  $ereignisse
     */
    public function __construct(
        public readonly array $ereignisse,
        public readonly ?string $zeiger,
    ) {}
}
