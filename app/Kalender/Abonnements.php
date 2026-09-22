<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Models\CalendarConnection;
use Illuminate\Support\Str;

/**
 * Abonnements bestellen, erneuern, beenden -- fuer jeden Anbieter gleich.
 *
 * **Wie erneuert wird, steht beim Anbieter.** Google bestellt einen neuen
 * Kanal und beendet den alten, Graph verlaengert den bestehenden. Was hier
 * steht, ist das, was in beiden Faellen gilt: das Geheimnis bleibt, solange
 * das Abonnement bleibt, und der Zustand wird danach festgeschrieben.
 *
 * Das Geheimnis ist unseres: beide Anbieter unterschreiben nicht, sie
 * **spiegeln** -- der Wert kommt bei jeder Zustellung zurueck. Er ist damit
 * die "Signatur" aus Entscheidung A14 und muss entsprechend zufaellig sein.
 */
final class Abonnements
{
    public function __construct(private readonly Kalenderdienste $dienste) {}

    public function erneuere(CalendarConnection $verbindung): void
    {
        // Ein bestehendes Geheimnis bleibt: bei Graph haengt es am Abonnement
        // und laesst sich beim Verlaengern nicht wechseln.
        $geheimnis = is_string($verbindung->channel_token) && $verbindung->channel_token !== ''
            ? $verbindung->channel_token
            : Str::random(48);

        $abonnement = $this->dienste->zu($verbindung)->erneuere(
            $verbindung,
            $geheimnis,
            $this->zustelladresse($verbindung),
        );

        $verbindung->channel_id = $abonnement->kennung;
        $verbindung->channel_token = $geheimnis;
        $verbindung->channel_resource_id = $abonnement->ressource;
        $verbindung->channel_expires_at = $abonnement->laeuftAb;
        $verbindung->save();
    }

    /** Beim Trennen. Ein Abonnement ohne Gegenstelle stellt weiter ins Leere zu. */
    public function beende(CalendarConnection $verbindung): void
    {
        $this->dienste->zu($verbindung)->beende($verbindung);

        $verbindung->channel_id = null;
        $verbindung->channel_token = null;
        $verbindung->channel_resource_id = null;
        $verbindung->channel_expires_at = null;
        $verbindung->save();
    }

    /** Je Anbieter eine eigene Adresse -- die Nutzlasten haben nichts gemein. */
    private function zustelladresse(CalendarConnection $verbindung): string
    {
        return route('kalender.'.$verbindung->provider->value.'.webhook');
    }
}
