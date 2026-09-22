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
        // Jeder Aufruf der Buchungsseite, den jemand erlaubt hat.
        //
        // **Das Modell wird nicht im Schema festgeschrieben**
        // (docs/fachlogik/attribution.md). Alle Touches werden gespeichert;
        // First, Last, Last-Non-Direct und Linear entstehen zur Abfragezeit.
        // Eine Zuordnung, die beim Schreiben faellt, laesst sich spaeter
        // nicht anders ansehen -- und genau das will eine Praxis wissen.
        Schema::create('attribution_touches', function (Blueprint $table): void {
            TenantSchema::base($table);

            // Zufallswert aus dem First-Party-Cookie. **Kein Personenbezug**
            // -- bis contact_id gesetzt ist. Ab da gilt fuer die Zeile, was
            // fuer einen Kontakt gilt.
            $table->string('visitor_id', 64);

            // Metas Klick-Kennung aus der Adresse.
            $table->string('click_id', 255)->nullable();

            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();

            // Aufgeloest aus den UTM-Angaben, die WP-27 selbst setzt.
            $table->string('campaign_external_id', 64)->nullable();
            $table->string('adset_external_id', 64)->nullable();
            $table->string('ad_external_id', 64)->nullable();

            // **Der Pfad, nicht die Adresse.** Der Abfrageteil traegt, was
            // jemand angehaengt hat -- im Zweifel eine Behandlung. Sobald der
            // Touch rueckwirkend mit contact_id verknuepft ist, stuende ein
            // Behandlungsname unverschluesselt neben einem Kontakt (Regel 3).
            // Was gebraucht wird, steht ohnehin in eigenen Spalten.
            $table->string('landing_path', 255)->nullable();

            // **Der Host, nicht die Seite.** Woher jemand kam, ist eine
            // Quelle; welche Seite genau, ist eine Aussage ueber ihn.
            $table->string('referrer_host', 191)->nullable();

            $table->datetime('occurred_at');

            // Rueckwirkend gesetzt, wenn aus dem Besucher ein Lead wird.
            TenantSchema::reference($table, 'contact_id', 'contacts', nullable: true, cascadeOnDelete: true);
            TenantSchema::reference($table, 'lead_id', 'leads', nullable: true);

            $table->datetimes();

            $table->index(['organization_id', 'visitor_id', 'occurred_at'], 'touch_besucher_idx');
            $table->index(['organization_id', 'contact_id'], 'touch_kontakt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_touches');
    }
};
