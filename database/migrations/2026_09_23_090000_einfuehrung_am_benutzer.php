<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // **Wann jemand die Einfuehrung gesehen hat, nicht ob.**
            //
            // Ein Zeitpunkt statt eines Kennzeichens: das Schema macht das
            // durchgehend so (deactivated_at, email_verified_at,
            // accepted_at). Er beantwortet dieselbe Frage und eine mehr --
            // wann es war.
            //
            // **Am Benutzer, nicht an der Organisation.** In
            // organizations.settings waere das Merkmal mandantenweit: die
            // zweite Mitarbeiterin saehe die Einfuehrung dann nie.
            //
            // DATETIME, nicht TIMESTAMP (Entscheidung A7).
            $table->datetime('einfuehrung_gesehen_at')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('einfuehrung_gesehen_at');
        });
    }
};
