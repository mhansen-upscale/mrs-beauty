<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Enums\CalendarProvider;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;

/**
 * Was ein Kalenderanbieter koennen muss.
 *
 * **Diese Schnittstelle ist in WP-15 entstanden, nicht in WP-14** --
 * docs/integrationen/kalender.md verlangt genau diese Reihenfolge: "Erst
 * beide umsetzen, dann abstrahieren. Ein gemeinsames Interface vor der
 * zweiten Umsetzung zu bauen fuehrt zu einer Abstraktion, die auf keinen von
 * beiden richtig passt."
 *
 * Der Schnitt laeuft **an der Nutzlast**, nicht an der Fachlogik. Ein
 * Anbieter liefert Ereignis-Objekte und nimmt einen Termin entgegen; wie er
 * daraus JSON macht, ob die Zeit einen Versatz traegt, ob die Eigenmarkierung
 * ein Feld oder ein angehaengtes Objekt ist -- das bleibt bei ihm. Was
 * dazwischen passiert (R1 bis R4, Blocker, Idempotenz, Ausfaelle), gibt es
 * genau einmal.
 *
 * Was ausdruecklich **nicht** hier steht: Fehlercodes, Adressen, Rechtenamen,
 * Hoechstlaufzeiten. Das sind keine gemeinsamen Begriffe, sondern zufaellig
 * aehnlich aussehende Eigenheiten.
 */
interface Kalenderdienst
{
    public function anbieter(): CalendarProvider;

    /*
    |--------------------------------------------------------------------------
    | Zugang
    |--------------------------------------------------------------------------
    */

    /** Die Adresse der Zustimmungsseite. */
    public function weiterleitung(string $state): string;

    public function tausche(string $code): Zugangsdaten;

    /** Beim Trennen: das Recht drueben zurueckgeben, soweit der Anbieter das kennt. */
    public function widerrufe(CalendarConnection $verbindung): void;

    /*
    |--------------------------------------------------------------------------
    | Kalender
    |--------------------------------------------------------------------------
    */

    public function kalender(CalendarConnection $verbindung): Kalenderangaben;

    /**
     * Ereignisse abrufen -- als Delta, wenn ein Zeiger vorliegt.
     *
     * Wirft SyncTokenVerfallen, wenn der Zeiger nicht mehr gilt. Das ist kein
     * Fehler, sondern der vorgesehene Weg zum Vollabgleich.
     */
    public function ereignisse(
        CalendarConnection $verbindung,
        ?string $zeiger,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): Ereignisseite;

    /** @return string Die Kennung des angelegten Events */
    public function lege(CalendarConnection $verbindung, Appointment $termin): string;

    /** @return bool false, wenn das Event drueben nicht mehr existiert (R3) */
    public function aktualisiere(CalendarConnection $verbindung, string $kennung, Appointment $termin): bool;

    public function entferne(CalendarConnection $verbindung, string $kennung): void;

    /*
    |--------------------------------------------------------------------------
    | Abonnement
    |--------------------------------------------------------------------------
    */

    public function beobachte(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement;

    /**
     * Erneuert das Abonnement der Verbindung.
     *
     * Wie, bleibt Sache des Anbieters: Google bestellt einen neuen Kanal und
     * beendet den alten, Graph verlaengert den bestehenden. Nach aussen ist
     * beides "das Abonnement gilt wieder eine Weile".
     */
    public function erneuere(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement;

    public function beende(CalendarConnection $verbindung): void;
}
