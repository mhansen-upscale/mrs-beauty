<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wann die Loeschung einer Kampagne beauftragt wurde.
 *
 * **Geloescht wird in der Warteschlange** (Regel 4), also vergeht zwischen
 * Klick und Verschwinden Zeit. Ohne diesen Zeitpunkt steht die Zeile
 * unveraendert da, als waere nichts geschehen -- gemeldet am 24.09.2026.
 *
 * **Und `vanished_at` waere das falsche Feld.** Das heisst "bei Meta
 * verschwunden, von uns nicht angefasst". Hier ist das Gegenteil der Fall:
 * wir entfernen sie, und bis es durch ist, soll man das sehen.
 *
 * Scheitert der Auftrag endgueltig, wird der Zeitpunkt wieder geleert -- dann
 * steht die Kampagne wieder normal da, mit dem Grund daneben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->datetime('deleting_at')->nullable()->after('vanished_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropColumn('deleting_at');
        });
    }
};
