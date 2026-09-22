<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            // Bisher las das Produkt Anzeigen nur. Jetzt legt es welche an --
            // und braucht dieselben vier Felder wie die Kampagne in WP-27.
            $table->string('sync_state', 16)->default('synced')->after('creative_external_id');
            $table->string('sync_error', 255)->nullable()->after('sync_state');

            // Der Ersatz fuer den Idempotenzschluessel, den Metas
            // Marketing-API nicht hat: er steht im Namen, den wir erzeugen.
            $table->string('client_token', 16)->nullable()->after('sync_error');
            $table->boolean('managed_by_us')->default(false)->after('client_token');

            // Woraus die Anzeige entstanden ist. Ohne diese Spur liesse sich
            // spaeter nicht mehr sagen, welcher geprueefte Text auf welcher
            // Anzeige steht -- und die HWG-Pruefung waere eine Zusage ohne
            // Beleg.
            //
            // **Nullbar**: eine aus Metas Bestand uebernommene Anzeige hat
            // keinen Entwurf hinter sich. Und **ohne Kaskade**: einen Entwurf
            // zu loeschen, zu dem eine bezahlte Anzeige laeuft, soll
            // scheitern statt still zu wirken.
            TenantSchema::reference($table, 'ad_suggestion_id', 'ad_suggestions', nullable: true, cascadeOnDelete: false);

            // Metas Kennung fuer das hochgeladene Bild. Steht hier, damit ein
            // zweiter Lauf es nicht ein zweites Mal hochlaedt.
            $table->string('image_hash', 128)->nullable()->after('ad_suggestion_id');
        });

        Schema::table('ads', function (Blueprint $table): void {
            $table->unique(['organization_id', 'client_token'], 'anzeige_merkmal_unique');
        });

        Schema::table('ad_accounts', function (Blueprint $table): void {
            // **Ohne Facebook-Seite keine Anzeige.** Metas Creative haengt an
            // einer Seite -- sie ist der Absender. Eingetragen wird sie von
            // Hand: sie automatisch zu lesen braeuchte `pages_show_list`, und
            // jede zusaetzliche Berechtigung verzoegert den App Review.
            $table->string('page_external_id', 64)->nullable()->after('business_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropUnique('anzeige_merkmal_unique');
        });

        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->dropColumn('page_external_id');
        });

        Schema::table('ads', function (Blueprint $table): void {
            $table->dropColumn(['sync_state', 'sync_error', 'client_token', 'managed_by_us', 'image_hash']);
        });
    }
};
