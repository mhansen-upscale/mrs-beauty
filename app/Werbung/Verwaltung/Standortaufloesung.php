<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Models\Location;
use App\Werbung\Meta\Graphleser;

/**
 * Metas Kennung fuer den Ort eines Standorts.
 *
 * Ein Umkreis laesst sich bei Meta nur um einen **bekannten Ort** legen, und
 * bekannt heisst: mit Metas eigener Kennung. Die kommt aus der Ortssuche --
 * einem lesenden Aufruf -- und wird am Standort festgehalten, damit nicht
 * jede Kampagne sie neu holt.
 *
 * **Ohne Kennung keine Kampagne.** Das ist die richtige Richtung: eine
 * Kampagne ohne Umkreis waere eine, die deutschlandweit ausliefert, und das
 * Geld einer Praxis in Hamburg fiele in Passau an.
 */
final class Standortaufloesung
{
    public function __construct(private readonly Graphleser $leser) {}

    public function kennung(Location $standort, string $token): ?string
    {
        if (is_string($standort->meta_city_key) && $standort->meta_city_key !== '') {
            return $standort->meta_city_key;
        }

        $ort = $standort->city;

        if (! is_string($ort) || trim($ort) === '') {
            return null;
        }

        $treffer = $this->leser->sammle($token, 'search', [
            'type' => 'adgeolocation',
            'location_types' => (string) json_encode(['city']),
            'q' => $ort,
            'country_code' => $standort->country,
            'limit' => 5,
        ]);

        foreach ($treffer as $zeile) {
            $kennung = $zeile['key'] ?? null;

            if (is_string($kennung) && $kennung !== '') {
                $standort->meta_city_key = $kennung;
                $standort->save();

                return $kennung;
            }
        }

        return null;
    }
}
