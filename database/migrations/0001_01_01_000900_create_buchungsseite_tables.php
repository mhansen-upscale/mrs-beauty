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
            // Der Zeitpunkt der Einwilligung auf der oeffentlichen
            // Buchungsseite.
            //
            // **Nicht das Einwilligungsmodell.** Das gehoert zu WP-18: je
            // Kanalidentitaet (Entscheidung D8), widerrufbar, mit Historie.
            // Hier steht der Nachweis fuer **diese** Buchung -- wer wann auf
            // dieser Seite zugestimmt hat. Eine Buchung vom Empfang traegt
            // ihn nicht, dort wird muendlich eingewilligt.
            $table->datetime('consent_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('consent_accepted_at');
        });
    }
};
