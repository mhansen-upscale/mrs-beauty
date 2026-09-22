<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Absicht und Zustand der Uebertragung (WP-27).
        //
        // **Was die Praxis will, und was bei Meta steht, sind zwei Dinge.**
        // Bis WP-26 war die Tabelle ein Spiegel: was drinstand, stand auch
        // dort. Ab jetzt kann eine Zeile eine Aenderung tragen, die noch
        // unterwegs ist -- oder eine, die Meta abgelehnt hat. Wer das nicht
        // unterscheidet, zeigt eine Budgeterhoehung an, die nie ankam.
        foreach (['ad_campaigns', 'ad_sets'] as $tabelle) {
            Schema::table($tabelle, function (Blueprint $table): void {
                $table->string('sync_state', 16)->default('synced')->after('effective_status');

                // Im Klartext, nicht als Code: Metas fachliche Ablehnungen
                // sind das Einzige, was die Praxis selbst beheben kann.
                $table->string('sync_error', 500)->nullable()->after('sync_state');

                // **Der Ersatz fuer einen Idempotenzschluessel, den Metas
                // Marketing-API nicht hat.** Er steht im Namen, den das
                // Produkt erzeugt -- ein Auftrag, dessen Antwort verlorenging,
                // findet seine Kampagne damit wieder, statt eine zweite mit
                // zweitem Budget anzulegen.
                $table->string('client_token', 16)->nullable()->after('sync_error');

                // Von uns angelegt oder aus Metas Bestand uebernommen. Eine
                // fremde Kampagne benennen wir nicht um.
                $table->boolean('managed_by_us')->default(false)->after('client_token');
            });
        }

        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->unique(['organization_id', 'client_token'], 'kampagne_merkmal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropUnique('kampagne_merkmal_unique');
        });

        foreach (['ad_campaigns', 'ad_sets'] as $tabelle) {
            Schema::table($tabelle, function (Blueprint $table): void {
                $table->dropColumn(['sync_state', 'sync_error', 'client_token', 'managed_by_us']);
            });
        }
    }
};
