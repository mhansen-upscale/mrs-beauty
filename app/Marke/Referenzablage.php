<?php

declare(strict_types=1);

namespace App\Marke;

use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\BrandReferenceKind;
use App\Models\BrandReference;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Referenzmaterial ablegen und entfernen.
 *
 * **Ohne Erklaerung keine Ablage.** Der Wortlaut wird mitgespeichert, nicht
 * nur ein Haekchen: wer spaeter fragt, was die Praxis zugesichert hat,
 * braucht den Text von damals. Eine Aenderung am Wortlaut in der
 * Konfiguration gilt ab dann, nicht rueckwirkend.
 *
 * **Kein Ablaufdatum** (anders als C6): Raeume, Team und Ablauf sind kein
 * ungefragt zugesandtes Foto, sondern Material, mit dem geworben wird.
 */
final class Referenzablage
{
    public function __construct(private readonly Anhangspeicher $anhaenge) {}

    public function lege(
        BrandReferenceKind $art,
        string $titel,
        string $inhalt,
        string $dateiname,
        User $wer,
        ?string $notiz = null,
    ): BrandReference {
        $referenz = new BrandReference;
        $referenz->kind = $art;
        $referenz->title = $titel;
        $referenz->note = $notiz;

        // Der Wortlaut von jetzt, nicht die Fundstelle darauf.
        $referenz->declaration_text = (string) config('mrs.brand.declaration');
        $referenz->declared_by_user_id = $wer->getKey();
        $referenz->declared_at = CarbonImmutable::now();
        $referenz->save();

        // Die Datei laeuft durch dieselbe Ablage wie jeder Anhang -- also
        // verschluesselt und durch die Virenpruefung (WP-33). Ohne
        // angebundenen Dienst bleibt sie 'unscanned', und was so vermerkt
        // ist, liefert das Produkt nicht aus.
        $this->anhaenge->lege(
            traeger: $referenz,
            inhalt: $inhalt,
            dateiname: $dateiname,
            kontext: AttachmentContext::BrandReference,
            wer: $wer,
        );

        return $referenz;
    }

    /**
     * **Loeschen heisst beides**: Datensatz und Datei.
     */
    public function entferne(BrandReference $referenz): void
    {
        foreach ($referenz->attachments()->get() as $anhang) {
            $this->anhaenge->entferne($anhang);
        }

        $referenz->delete();
    }
}
