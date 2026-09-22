<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metas Zahlen, taeglich, je Ebene.
        //
        // **Nur Grundwerte.** CTR, CPC und CPM liefert Meta mit -- und sie
        // sind aus diesen vier Spalten zu rechnen. Beides zu speichern hiesse
        // zwei Zahlen fuer dieselbe Aussage, und die weichen ab, sobald Meta
        // rundet oder einen Tag nachtraeglich korrigiert. Dasselbe Muster wie
        // bei der Nutzungsuebersicht in WP-06.
        //
        // **Reichweite fehlt mit Absicht.** Sie zaehlt verschiedene Menschen
        // und laesst sich nicht ueber Tage addieren. Eine nicht summierbare
        // Zahl neben summierbaren wird irgendwann summiert.
        Schema::create('ad_insights', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'ad_account_id', 'ad_accounts', cascadeOnDelete: true);

            // 'campaign', 'adset', 'ad'. Die Ebenen unterhalb der Kampagne
            // fallen nach zwoelf Monaten weg (Entscheidung P9).
            $table->string('level', 16);

            // Metas Kennung der Zeile -- keine Fremdschluesselbeziehung: die
            // Zahlen ueberleben eine Kampagne, die bei Meta verschwindet.
            $table->string('external_id', 64);

            // **Ein Datum, kein Zeitstempel.** Insights-Tage laufen in der
            // Zeitzone des Werbekontos, nicht in UTC. Eine Umrechnung waere
            // hier falsch, anders als bei den Laufzeiten in WP-26.
            $table->date('stat_date');

            // In der kleinsten Einheit der Kontowaehrung, als Ganzzahl.
            // Meta liefert Ausgaben als Dezimalzeichenkette ("25.43"),
            // Budgets dagegen in kleinster Einheit ("2500") -- zwei
            // Konventionen in einer API.
            $table->unsignedBigInteger('spend_minor')->default(0);

            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('link_clicks')->default(0);

            // Was **Meta** als Ergebnis zaehlt. Nicht dasselbe wie ein Lead
            // im Produkt -- der entsteht in WP-32 aus der Zuordnung.
            $table->unsignedBigInteger('leads')->default(0);

            $table->datetime('synced_at')->nullable();
            $table->datetimes();

            // Eine Zeile je Ebene, Kennung und Tag. Der Abgleich schreibt
            // ueber ein nachlaufendes Fenster: Metas Zahlen aendern sich bis
            // zu 28 Tage rueckwirkend.
            $table->unique(['organization_id', 'level', 'external_id', 'stat_date'], 'kennzahl_unique');

            $table->index(['organization_id', 'level', 'stat_date'], 'kennzahl_zeitraum_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_insights');
    }
};
