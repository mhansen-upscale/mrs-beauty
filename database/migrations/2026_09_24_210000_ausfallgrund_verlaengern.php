<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Ausfallgrund am Werbekonto bekommt Platz.
 *
 * **Dieselbe Falle wie bei `sync_error`, an derselben Stelle wiederholt.**
 * Die Spalte war auf 64 Zeichen ausgelegt, weil dort ein Kurzgrund stand --
 * `token_invalid`, `permission_missing`. Seit dem 24.09.2026 steht dort
 * Metas Satz, denn ein Kurzgrund im Hinweiskasten ist ein Code fuer die
 * Praxis. Metas Satz zur Sicherheitspruefung ist 260 Zeichen lang, und das
 * Speichern des Ausfalls warf dabei selbst:
 *
 *     SQLSTATE[22001]: Data too long for column 'last_error'
 *
 * Damit war der Grund fort, der Auftrag scheiterte an etwas anderem als dem
 * eigentlichen Problem, und die Praxis las "Die Ursache liegt bei uns".
 *
 * Nur `ad_accounts`: die Verbindungen in `kalendersync` und `kanaele` fuehren
 * weiterhin Kurzgruende. Was keinen laengeren Text bekommt, braucht keine
 * breitere Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->text('last_error')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->string('last_error', 64)->nullable()->change();
        });
    }
};
