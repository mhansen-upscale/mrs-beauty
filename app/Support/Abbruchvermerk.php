<?php

declare(strict_types=1);

namespace App\Support;

use App\Werbung\Werbefehler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Was dasteht, wenn ein Auftrag aufgibt.
 *
 * **Der Grund darf nicht verlorengehen.** Bis zum 24.09.2026 las diese
 * Stelle ausschliesslich den zuletzt vermerkten Grund und warf den Fehler,
 * an dem der Auftrag tatsaechlich scheiterte, weg. Stand dort nichts -- weil
 * der Fehlschlag gar nicht von Meta kam und deshalb nie durch
 * `vermerkeFehler` lief --, las die Praxis:
 *
 *     Nach mehreren Versuchen aufgegeben. Zuletzt: kein Grund vermerkt.
 *
 * Ein Satz, der nichts sagt und niemandem einen naechsten Schritt gibt.
 *
 * **Drei Quellen, in dieser Reihenfolge:** der beim Versuch vermerkte Grund
 * (der genaueste, er kennt die Stelle), sonst die Einordnung des Fehlers
 * selbst, sonst ein ehrlicher Satz darueber, dass die Ursache bei uns liegt.
 * Der technische Teil geht ins Protokoll, nicht in die Oberflaeche: ein
 * Klassenname hilft der Praxis nicht.
 */
final class Abbruchvermerk
{
    private const VORSPANN = 'Nach mehreren Versuchen aufgegeben. Zuletzt: ';

    /**
     * @param  string|null  $vermerkt  Der beim Versuch gespeicherte Grund.
     * @param  string  $auftrag  Was uebertragen werden sollte -- fuer das Protokoll.
     * @param  string  $kennung  Welcher Datensatz -- ebenfalls nur fuers Protokoll.
     */
    public static function satz(Throwable $grund, ?string $vermerkt, string $auftrag, string $kennung): string
    {
        if ($vermerkt !== null && trim($vermerkt) !== '') {
            return self::VORSPANN.$vermerkt;
        }

        if ($grund instanceof Werbefehler) {
            return self::VORSPANN
                .($grund->einordnung->klartext ?? 'Meta hat die Übertragung abgelehnt.');
        }

        // **Hier endet die Spur, wenn wir sie nicht festhalten.** Ein Fehler,
        // der nicht von Meta kommt, ist einer von uns: ein Tippfehler im
        // Auftrag, eine Zeitueberschreitung, ein fehlendes Feld. Die Praxis
        // kann daran nichts tun -- wir schon, aber nur mit dem Protokoll.
        Log::error('Auftrag endgueltig gescheitert', [
            'auftrag' => $auftrag,
            'kennung' => $kennung,
            'art' => $grund::class,
            'meldung' => $grund->getMessage(),
            'datei' => $grund->getFile().':'.$grund->getLine(),
        ]);

        return 'Nach mehreren Versuchen aufgegeben. Die Ursache liegt bei uns, nicht bei Meta — wir haben sie protokolliert und sehen sie uns an.';
    }
}
