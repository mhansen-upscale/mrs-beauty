<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Woher ein Anhang stammt -- und wie lange er bleiben darf.
 *
 * Entscheidung C6: **Chat-Anhaenge tragen ein Pflicht-Ablaufdatum.** Der
 * Grund steht in docs/produkt.md: ungefragt zugesandte Fotos. Eine Praxis fuer
 * aesthetische Behandlungen bekommt sie taeglich, niemand hat danach gefragt,
 * und sie sind Gesundheitsdaten nach Artikel 9 DSGVO.
 */
enum AttachmentContext: string
{
    /** Aus einer Konversation. Ablaufdatum ist Pflicht. */
    case Chat = 'chat';

    /** Vom Team hochgeladen -- Einwilligungsbogen, Rechnung. */
    case Document = 'document';

    /**
     * Referenzmaterial der Marke (WP-29).
     *
     * **Kein Ablaufdatum.** Raeume, Team und Ablauf sind kein ungefragt
     * zugesandtes Foto, sondern Material, mit dem geworben wird -- und ein
     * Vorbild, das nach 90 Tagen verschwindet, waere keines.
     *
     * Patientenaufnahmen gehoeren nicht hierher. Zugesichert wird das beim
     * Hochladen im Wortlaut; geprueft wird es erst mit WP-30.
     */
    case BrandReference = 'brand_reference';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Aus dem Chat',
            self::Document => 'Dokument',
            self::BrandReference => 'Referenzmaterial',
        };
    }

    /** Braucht dieser Anhang ein Ablaufdatum? */
    public function brauchtAblauf(): bool
    {
        return $this === self::Chat;
    }
}
