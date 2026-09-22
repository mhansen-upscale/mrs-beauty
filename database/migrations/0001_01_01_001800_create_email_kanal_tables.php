<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // **Verschluesselt** (Regel 3). Eine Betreffzeile traegt bei
            // diesem Produkt regelmaessig einen Personenbezug und manchmal
            // ein Gesundheitsdatum -- "Frage zu meiner Unterspritzung" ist
            // ein gewoehnlicher Betreff.
            //
            // Regel 5 gilt hier ebenso: `docs/fachlogik/agent.md` nennt
            // Betreffzeilen ausdruecklich als Ort, an dem Anweisungen
            // auftauchen. Was hier steht, sind Daten.
            $table->binary('subject')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('subject');
        });
    }
};
