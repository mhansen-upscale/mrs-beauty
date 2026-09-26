<?php

declare(strict_types=1);

use App\Models\AdSuggestion;
use App\Support\Schema\TenantSchema;
use App\Support\Uuid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Die Grafiken eines Entwurfs, je Format (WP-31b, Entscheidung C13).
        //
        // **Die Datei bleibt ein Anhang am Entwurf** -- verschluesselt,
        // virengeprueft und aufbewahrt wie jede andere. Hier steht nur,
        // welches Format sie hat und zu welchem Satz sie gehoert.
        Schema::create('ad_suggestion_images', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'ad_suggestion_id', 'ad_suggestions', cascadeOnDelete: true);
            TenantSchema::reference($table, 'attachment_id', 'attachments', cascadeOnDelete: true);

            $table->string('format', 8);

            // **Der Satz ist die Einheit der Abrechnung** (B13, angepasst am
            // 27.09.2026): drei Formate aus einem Auftrag sind eine Grafik.
            // Keine eigene Tabelle -- ein Satz hat nichts ausser seinen
            // Dateien.
            $table->binary('batch', 16, true);

            $table->datetimes();

            $table->index(['organization_id', 'created_at'], 'grafik_abrechnung_idx');
        });

        // **Was schon da ist, war quadratisch** -- erzeugt mit
        // `aspect_ratio: 1:1`. Jede bisherige Grafik wird ein eigener Satz:
        // gezaehlt wurde bis heute je Datei, und die Abrechnung vergangener
        // Monate soll nicht nachtraeglich eine andere Zahl zeigen.
        DB::table('attachments')
            ->where('attachable_type', AdSuggestion::class)
            ->whereIn('attachable_id', DB::table('ad_suggestions')->select('id'))
            ->orderBy('id')
            ->each(function (object $anhang): void {
                DB::table('ad_suggestion_images')->insert([
                    'id' => Uuid::generate(),
                    'organization_id' => $anhang->organization_id,
                    'ad_suggestion_id' => $anhang->attachable_id,
                    'attachment_id' => $anhang->id,
                    'format' => '1x1',
                    'batch' => Uuid::generate(),
                    'created_at' => $anhang->created_at,
                    'updated_at' => $anhang->created_at,
                ]);
            });

        Schema::table('ads', function (Blueprint $table): void {
            // **Je Format die Kennung von Meta -- und der Anhang, zu dem sie
            // gehoert.** Eine neue Grafik ist ein neues Bild; mit der Kennung
            // der alten ginge das alte hinaus.
            //
            // Die bisherige Einzelkennung wird nicht uebernommen: der Anhang
            // dazu ist nicht mehr zu bestimmen, und eine Anzeige, die noch
            // nicht bei Meta steht, braucht ohnehin alle drei Formate. Meta
            // bildet die Kennung aus dem Inhalt -- ein erneutes Hochladen
            // ergibt dieselbe.
            $table->json('image_hashes')->nullable()->after('image_hash');
        });

        Schema::table('ads', function (Blueprint $table): void {
            $table->dropColumn('image_hash');
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->string('image_hash', 128)->nullable()->after('ad_suggestion_id');
        });

        Schema::table('ads', function (Blueprint $table): void {
            $table->dropColumn('image_hashes');
        });

        Schema::dropIfExists('ad_suggestion_images');
    }
};
