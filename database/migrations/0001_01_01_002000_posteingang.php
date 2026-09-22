<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            // **Der Gelesen-Stand der Praxis, nicht der einer Person.**
            //
            // Eine Praxis mit drei Personen am Empfang braucht keinen
            // Zaehler je Benutzer -- sie braucht zu wissen, ob *jemand* die
            // Nachricht schon gesehen hat. Ein Stand je Person waere eine
            // Tabelle mehr, drei Zahlen an der Wand und dieselbe Frage,
            // dreimal beantwortet.
            $table->datetime('last_read_at')->nullable()->after('last_outbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('last_read_at');
        });
    }
};
