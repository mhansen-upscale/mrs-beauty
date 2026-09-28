<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oeffentlich;

use App\Abrechnung\Paket;
use App\Http\Controllers\Controller;
use App\Oeffentlich\Formularmerkmal;
use App\Oeffentlich\Preisangaben;
use App\Oeffentlich\Seitenmeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Startseite (WP-38): was das Produkt tut, was es kostet, wie man es
 * bekommt.
 *
 * **Fuer alle, auch fuer Angemeldete.** Wer angemeldet ist, sieht in der
 * Kopfzeile "Zum Dashboard" statt "Anmelden" -- umgeleitet wird niemand. Die
 * Seite ist der Ort, an den das Abmelden fuehrt.
 */
final class StartseiteController extends Controller
{
    public const TITEL = 'Werbung, Kommunikation und Termine für ästhetische Praxen';

    public const BESCHREIBUNG = 'Mrs. Beauty verbindet Meta-Werbung, WhatsApp und E-Mail, Online-Buchung und Warteliste in einem System — mit KI-Empfang, HWG-Prüfhilfe vor jeder Veröffentlichung und einer Auswertung von der Anzeige bis zum Termin.';

    public function __invoke(Paket $paket, Formularmerkmal $merkmal): Response
    {
        return Inertia::render('oeffentlich/Startseite', [
            // Die geltende Fassung (B20) -- dieselbe, die die Kasse abrechnet.
            'preise' => Preisangaben::aus($paket->geltende())?->alsArray(),

            'demoformular' => [
                'merkmal' => $merkmal->erzeuge(),
            ],

            // Fuer "Lieber direkt?" und den Fuss: dieselben Angaben wie im
            // Impressum.
            'anbieter' => array_map(strval(...), (array) config('mrs.oeffentlich.anbieter')),
        ])->withViewData('meta', Seitenmeta::fuer(self::TITEL, self::BESCHREIBUNG, '/'));
    }
}
