<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der zweite Faktor (WP-35, Entscheidung C16).
 *
 * **Am Benutzer, nicht in einer eigenen Tabelle.** Eine Person hat hoechstens
 * ein Verfahren, und der Betreiber gehoert zu keiner Organisation -- eine
 * Tabelle mit organization_id muesste TenantModel sein und scheiterte bei der
 * Anmeldung, bevor ein Mandant bekannt ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // VARCHAR mit PHP-Enum, kein MySQL-ENUM (Entscheidung A11).
            $table->string('zwei_faktor_verfahren', 16)->nullable()->after('einfuehrung_gesehen_at');

            // **Mit dem App-Schluessel verschluesselt, nicht mit dem der
            // Praxis.** Betreiber haben keine Praxis, und das Crypto-Loeschen
            // einer Praxis (A5) sperrte die Person sonst aus ihrem Konto aus.
            $table->text('zwei_faktor_geheimnis')->nullable()->after('zwei_faktor_verfahren');

            // Nur SHA-256 der Wiederherstellungscodes. Die Codes selbst sieht
            // die Person einmal, danach niemand mehr.
            $table->json('zwei_faktor_wiederherstellung')->nullable()->after('zwei_faktor_geheimnis');

            // Erst ein bestaetigtes Verfahren zaehlt. DATETIME (A7).
            $table->datetime('zwei_faktor_bestaetigt_at')->nullable()->after('zwei_faktor_wiederherstellung');

            // Der zuletzt angenommene Takt der App -- derselbe Code wirkt
            // genau einmal.
            $table->unsignedBigInteger('zwei_faktor_letzter_schritt')->nullable()->after('zwei_faktor_bestaetigt_at');

            // "Spaeter" am Hinweis. Ein Zeitpunkt statt eines Kennzeichens:
            // nach einer Pause erscheint er wieder.
            $table->datetime('zwei_faktor_hinweis_ausgeblendet_at')->nullable()->after('zwei_faktor_letzter_schritt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'zwei_faktor_verfahren',
                'zwei_faktor_geheimnis',
                'zwei_faktor_wiederherstellung',
                'zwei_faktor_bestaetigt_at',
                'zwei_faktor_letzter_schritt',
                'zwei_faktor_hinweis_ausgeblendet_at',
            ]);
        });
    }
};
