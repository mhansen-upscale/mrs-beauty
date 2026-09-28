<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Ein Konto, ein Behandler
|--------------------------------------------------------------------------
|
| Eine Behandlerin sieht unter Termine den Kalender des Behandlers, an dem
| ihr Konto haengt. Haengt es an zweien, entschiede die Reihenfolge der
| Abfrage, welcher Kalender "der eigene" ist. Die Pruefung im Controller
| meldet das am Feld; der Index sorgt dafuer, dass es auch am Controller
| vorbei nicht vorkommt.
|
| Mehrere NULL -- Behandler ohne Konto -- laesst ein UNIQUE in MySQL zu.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        // Zweifach verbundene Konten gab es ueber die Oberflaeche nie -- sie
        // konnte gar nicht verbinden. Falls eine Zeile von Hand entstanden
        // ist, behaelt der aelteste Behandler das Konto, die uebrigen werden
        // geloest. Am Modell vorbei: eine Migration laeuft ohne Mandanten.
        $doppelt = DB::table('practitioners')
            ->select(['organization_id', 'user_id'])
            ->whereNotNull('user_id')
            ->groupBy(['organization_id', 'user_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($doppelt as $paar) {
            $behalten = DB::table('practitioners')
                ->where('organization_id', $paar->organization_id)
                ->where('user_id', $paar->user_id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            DB::table('practitioners')
                ->where('organization_id', $paar->organization_id)
                ->where('user_id', $paar->user_id)
                ->where('id', '!=', $behalten)
                ->update(['user_id' => null]);
        }

        Schema::table('practitioners', function (Blueprint $table): void {
            $table->unique(['organization_id', 'user_id'], 'behandler_konto_unique');
        });
    }

    public function down(): void
    {
        Schema::table('practitioners', function (Blueprint $table): void {
            $table->dropUnique('behandler_konto_unique');
        });
    }
};
