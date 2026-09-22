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
        // Das Werbekonto einer Praxis.
        //
        // **Es gehoert dem Kunden** (Entscheidung B1). Wir greifen ueber eine
        // Partnerschaft im Business Manager darauf zu, nicht ueber eine
        // Uebertragung -- bei Kuendigung oder Sperrung ist das der
        // Unterschied zwischen einem Aergernis und einem Rechtsstreit.
        //
        // Eines je Mandant. Mehrere waeren eine Frage, die niemand gestellt
        // hat, und jede Auswertung muesste sie ab da mitschleppen.
        Schema::create('ad_accounts', function (Blueprint $table): void {
            TenantSchema::base($table);

            // Metas Kennung, mit Praefix: act_1234567890.
            $table->string('external_id', 64);

            // Die Kennung des Business Managers, ueber den die Partnerschaft
            // laeuft. Bei einem Wechsel bricht der Zugriff, und dann ist die
            // Frage "welches Business?" die erste.
            $table->string('business_external_id', 64)->nullable();

            $table->string('name', 191)->nullable();

            // Betraege kommen in kleinster Einheit und in dieser Waehrung.
            // Ohne sie addiert eine Auswertung spaeter Euro und Franken.
            $table->string('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('status', 16)->default('active');

            // Verschluesselt (Regel 3): wer das Token hat, kann Geld der
            // Praxis ausgeben.
            $table->binary('access_token')->nullable();
            $table->datetime('token_expires_at')->nullable();

            $table->datetime('connected_at')->nullable();
            $table->datetime('disconnected_at')->nullable();
            $table->datetime('last_synced_at')->nullable();

            $table->string('last_error', 64)->nullable();
            $table->datetime('failed_at')->nullable();
            $table->datetimes();

            $table->unique(['organization_id', 'external_id'], 'werbekonto_unique');
        });

        // Kampagne, Anzeigengruppe, Anzeige -- Metas drei Ebenen.
        //
        // **Die Namen liegen verschluesselt.** Eine importierte Kampagne kann
        // "Botox Herbst" heissen; die Praxis hat sie so benannt, bevor sie uns
        // kannte, und wir aendern fremde Namen nicht. Ab WP-32 friert dieser
        // Name als attribution_snapshot am Termin ein (D13) -- ein
        // Behandlungsname in einem offenen Feld unmittelbar neben einem
        // Kontakt. Der Preis dafuer steht im Briefing: sortiert und gesucht
        // wird in PHP, nicht in SQL (wie P8).
        Schema::create('ad_campaigns', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'ad_account_id', 'ad_accounts', cascadeOnDelete: true);

            $table->string('external_id', 64);
            $table->binary('name')->nullable();

            // Metas zwei Zustandsfelder. `status` ist, was jemand gesetzt hat;
            // `effective_status` ist, was tatsaechlich gilt -- eine Kampagne
            // kann ACTIVE sein, waehrend das Werbekonto gesperrt ist.
            $table->string('status', 32)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->string('objective', 64)->nullable();

            // In kleinster Einheit der Kontowaehrung, als Ganzzahl.
            $table->unsignedBigInteger('daily_budget')->nullable();
            $table->unsignedBigInteger('lifetime_budget')->nullable();

            $table->datetime('starts_at')->nullable();
            $table->datetime('stops_at')->nullable();

            $table->datetime('synced_at')->nullable();

            // Was bei Meta verschwindet, wird markiert, nicht geloescht:
            // Auswertungen und Attribution zeigen weiter darauf.
            $table->datetime('vanished_at')->nullable();

            $table->datetimes();

            $table->unique(['organization_id', 'external_id'], 'kampagne_unique');
            $table->index(['ad_account_id', 'vanished_at'], 'kampagne_konto_idx');
        });

        Schema::create('ad_sets', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'ad_account_id', 'ad_accounts', cascadeOnDelete: true);
            TenantSchema::reference($table, 'ad_campaign_id', 'ad_campaigns', cascadeOnDelete: true);

            $table->string('external_id', 64);
            $table->binary('name')->nullable();

            $table->string('status', 32)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->string('optimization_goal', 64)->nullable();

            $table->unsignedBigInteger('daily_budget')->nullable();
            $table->unsignedBigInteger('lifetime_budget')->nullable();

            $table->datetime('starts_at')->nullable();
            $table->datetime('stops_at')->nullable();

            $table->datetime('synced_at')->nullable();
            $table->datetime('vanished_at')->nullable();
            $table->datetimes();

            $table->unique(['organization_id', 'external_id'], 'anzeigengruppe_unique');
            $table->index(['ad_campaign_id', 'vanished_at'], 'anzeigengruppe_kampagne_idx');
        });

        Schema::create('ads', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'ad_account_id', 'ad_accounts', cascadeOnDelete: true);
            TenantSchema::reference($table, 'ad_set_id', 'ad_sets', cascadeOnDelete: true);

            $table->string('external_id', 64);
            $table->binary('name')->nullable();

            $table->string('status', 32)->nullable();
            $table->string('effective_status', 32)->nullable();

            // Nur die Kennung. Der Inhalt einer Anzeige gehoert zu WP-31 und
            // wird hier nicht gelesen.
            $table->string('creative_external_id', 64)->nullable();

            $table->datetime('synced_at')->nullable();
            $table->datetime('vanished_at')->nullable();
            $table->datetimes();

            $table->unique(['organization_id', 'external_id'], 'anzeige_unique');
            $table->index(['ad_set_id', 'vanished_at'], 'anzeige_gruppe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
        Schema::dropIfExists('ad_sets');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_accounts');
    }
};
