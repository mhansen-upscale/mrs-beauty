<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Vermerk zu einer gescheiterten Uebertragung bekommt Platz.
 *
 * **Am 24.09.2026 hat eine zu kurze Spalte einen Auftrag umgebracht.** Meta
 * antwortete mit dem Satz, den die Praxis gebraucht haette -- ein Business-
 * Admin muss eine Richtlinie akzeptieren, samt Link in den Hilfebereich. Der
 * Satz war 290 Zeichen lang, `ads.sync_error` fasste 255. Die Folge war nicht
 * ein abgeschnittener Text, sondern ein `QueryException` mitten im
 * Festhalten des Fehlers:
 *
 *     SQLSTATE[22001]: Data too long for column 'sync_error'
 *
 * Damit war der Grund fort, der Auftrag scheiterte an etwas anderem als dem
 * eigentlichen Problem, und die Praxis las am Ende "Die Ursache liegt bei
 * uns". Drei Runden Fehlersuche fuer eine Spaltenbreite.
 *
 * **`text` statt einer groesseren Zahl.** Eine Zahl waere wieder nur geraten:
 * Metas Meldungen tragen Hilfelinks, und die werden nicht kuerzer. Der
 * Gegenpart im Code ist die Obergrenze in GehoertZurWerbestruktur -- die
 * Spalte gibt Platz, das Modell gibt die Grenze.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabellen = ['ads', 'ad_campaigns', 'ad_sets'];

    public function up(): void
    {
        foreach ($this->tabellen as $tabelle) {
            Schema::table($tabelle, function (Blueprint $table): void {
                $table->text('sync_error')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Zurueck auf die alten Breiten. Was laenger ist, wird dabei
        // abgeschnitten -- ein Vermerk ist kein Datensatz, den jemand
        // vermisst.
        foreach (['ads' => 255, 'ad_campaigns' => 500, 'ad_sets' => 500] as $tabelle => $breite) {
            Schema::table($tabelle, function (Blueprint $table) use ($breite): void {
                $table->string('sync_error', $breite)->nullable()->change();
            });
        }
    }
};
