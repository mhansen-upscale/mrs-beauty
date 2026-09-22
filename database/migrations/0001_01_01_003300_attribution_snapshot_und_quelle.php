<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // **Eine Kopie, kein Verweis** (Entscheidung D13).
            //
            // Kampagnen werden umbenannt, pausiert und geloescht. Ein Verweis
            // wuerde mit umbenannt, und die Auswertung eines alten Termins
            // waere nach einem halben Jahr wertlos.
            //
            // Verschluesselt, weil der Kampagnenname eine
            // Behandlungsbezeichnung tragen kann (WP-26) -- und hier steht er
            // unmittelbar neben einem Kontakt.
            $table->binary('attribution_snapshot')->nullable()->after('booked_via');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('attribution_snapshot');
        });
    }
};
