<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oeffentlich;

use App\Http\Controllers\Controller;
use App\Oeffentlich\Seitenmeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Impressum und Datenschutzerklaerung des Betreibers (WP-38).
 *
 * **Die Angaben kommen aus der Konfiguration**, die Seiten setzen sie nur:
 * Anbieter, Cookies, Drittanbieter und Fristen stehen dort an einer Stelle,
 * und tests/Feature/Oeffentlich/OhneTrackingTest.php haelt die Seiten gegen
 * dieselbe Liste.
 */
final class RechtstexteController extends Controller
{
    public function impressum(): Response
    {
        return Inertia::render('oeffentlich/Impressum', [
            'anbieter' => $this->anbieter(),
        ])->withViewData('meta', Seitenmeta::fuer(
            'Impressum',
            'Anbieterkennzeichnung von Mrs. Beauty nach § 5 DDG.',
            '/impressum',
        ));
    }

    public function datenschutzerklaerung(): Response
    {
        return Inertia::render('oeffentlich/Datenschutzerklaerung', [
            'anbieter' => $this->anbieter(),

            // Genau die Cookies, die jede Seite setzt: die Sitzung und das
            // Merkmal gegen gefaelschte Formulare. Mehr nicht (WP-38 AK 10).
            'cookies' => [
                ['name' => (string) config('session.cookie'), 'zweck' => 'Hält die Sitzung, etwa zwischen Formular und Antwort.', 'dauer' => (int) config('session.lifetime').' Minuten'],
                ['name' => 'XSRF-TOKEN', 'zweck' => 'Schützt Formulare davor, von fremden Seiten abgeschickt zu werden.', 'dauer' => (int) config('session.lifetime').' Minuten'],
            ],

            'drittanbieter' => array_map(
                fn (string $host, array $angaben): array => ['host' => $host, 'anbieter' => (string) $angaben['anbieter'], 'zweck' => (string) $angaben['zweck']],
                array_keys((array) config('mrs.oeffentlich.drittanbieter')),
                array_values((array) config('mrs.oeffentlich.drittanbieter')),
            ),

            'aufbewahrungMonate' => (int) config('mrs.oeffentlich.demoanfragen.aufbewahrung_monate'),
        ])->withViewData('meta', Seitenmeta::fuer(
            'Datenschutzerklärung',
            'Wie Mrs. Beauty auf dieser Website personenbezogene Daten verarbeitet — ohne Tracking und ohne Cookie-Banner.',
            '/datenschutzerklaerung',
        ));
    }

    /**
     * @return array<string, string>
     */
    private function anbieter(): array
    {
        return array_map(strval(...), (array) config('mrs.oeffentlich.anbieter'));
    }
}
