<?php

declare(strict_types=1);

namespace App\Anzeigen;

use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\Bildformat;
use App\Models\AdSuggestion;
use App\Models\AdSuggestionImage;
use App\Models\User;

/**
 * Legt eine erzeugte Grafik ab -- ein Format eines Satzes (WP-31b).
 *
 * **Liegt bei uns, nicht beim Anbieter** (C10): als verschluesselter Anhang
 * am Entwurf, mit Virenpruefung wie jeder andere. Dazu ein Datensatz, der
 * Format und Satz festhaelt -- der Satz ist die Einheit, in der gezaehlt wird
 * (B13).
 *
 * **Die vorige bleibt liegen.** Eine Grafik kostet Geld, und wer sie spurlos
 * ueberschreibt, kann weder nachrechnen, wofuer bezahlt wurde, noch zur
 * besseren Fassung zurueck. Gezeigt wird je Format die neueste.
 */
final class Grafikablage
{
    public function __construct(private readonly Anhangspeicher $anhaenge) {}

    /**
     * @param  string  $satz  Rohbytes -- dieselben fuer alle Formate eines Auftrags
     */
    public function lege(AdSuggestion $vorschlag, Bildformat $format, Bild $bild, string $satz, ?User $wer): AdSuggestionImage
    {
        $anhang = $this->anhaenge->lege(
            traeger: $vorschlag,
            inhalt: $bild->inhalt,
            dateiname: 'anzeige-'.$vorschlag->uuid.'-'.$format->value.'.'.$this->endung($bild->mime),
            kontext: AttachmentContext::BrandReference,
            wer: $wer,
        );

        $grafik = new AdSuggestionImage;
        $grafik->ad_suggestion_id = $vorschlag->getKey();
        $grafik->attachment_id = $anhang->getKey();
        $grafik->format = $format;
        $grafik->batch = $satz;
        $grafik->save();

        return $grafik;
    }

    private function endung(string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            default => 'png',
        };
    }
}
