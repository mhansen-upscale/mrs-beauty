<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Ein QR-Code als SVG.
 *
 * Serverseitig und nicht im Browser: die Seiten dieses Produkts laden keine
 * Skripte von fremden Adressen, und ein QR-Code ist kein Grund, damit
 * anzufangen. SVG statt PNG, weil er auf einem Plakat genauso scharf sein
 * soll wie auf dem Bildschirm.
 */
final class QrCode
{
    public static function svg(string $inhalt, int $groesse = 240): string
    {
        $schreiber = new Writer(new ImageRenderer(
            new RendererStyle($groesse, 1),
            new SvgImageBackEnd,
        ));

        return $schreiber->writeString($inhalt);
    }
}
