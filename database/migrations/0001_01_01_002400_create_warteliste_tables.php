<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wer auf einen frueheren Termin wartet.
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'contact_id', 'contacts', cascadeOnDelete: true);
            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types', cascadeOnDelete: true);

            // K4: null heisst "jeder Behandler".
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', nullable: true);

            $table->string('status', 16)->default('active');

            // K3: alle Standorte oder die im Pivot.
            $table->boolean('all_locations')->default(true);

            // K5: der Zeitraum, in dem ueberhaupt etwas passt.
            $table->date('earliest_date');
            $table->date('latest_date');

            // K6: Bitmaske der Wochentage, Montag ist Bit 0. Sieben Bits in
            // einem Byte -- eine Pivot-Tabelle fuer sieben feste Werte waere
            // ein Join fuer nichts.
            $table->unsignedTinyInteger('weekday_mask')->default(127);

            // K7: [{"von":"09:00","bis":"12:00"}, ...]. Kein Personenbezug,
            // deshalb unverschluesselt und damit abfragbar.
            $table->json('time_windows')->nullable();

            // **K8 -- das Feld, an dem die Warteliste steht und faellt.**
            // Manche koennen in zwei Stunden da sein, andere brauchen zwei
            // Tage. Ohne das verschickt das System ueberwiegend Angebote,
            // die niemand annehmen kann, und jeder Fehlversuch kostet Geld.
            $table->unsignedSmallInteger('min_notice_hours')->default(24);

            // Rangfolge: Prioritaet absteigend, dann wer laenger wartet.
            $table->unsignedTinyInteger('priority')->default(0);

            $table->datetime('expires_at');
            $table->unsignedSmallInteger('offers_sent_count')->default(0);
            $table->datetime('last_offered_at')->nullable();

            $table->datetimes();

            $table->index(['organization_id', 'status', 'priority'], 'warteliste_rang_idx');
            $table->index(['organization_id', 'appointment_type_id', 'status'], 'warteliste_art_idx');
        });

        // K3, wenn nicht alle Standorte passen.
        Schema::create('waitlist_entry_location', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'waitlist_entry_id', 'waitlist_entries', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->datetimes();

            $table->unique(['waitlist_entry_id', 'location_id'], 'warteliste_standort_unique');
        });

        // Ein Angebot an einen Eintrag fuer einen konkreten Zeitpunkt.
        Schema::create('waitlist_offers', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'waitlist_entry_id', 'waitlist_entries', cascadeOnDelete: true);
            TenantSchema::reference($table, 'slot_hold_id', 'slot_holds', nullable: true);
            TenantSchema::reference($table, 'appointment_id', 'appointments', nullable: true);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners');
            TenantSchema::reference($table, 'location_id', 'locations');
            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types');

            $table->string('status', 16)->default('pending');
            $table->string('trigger', 16);

            // Der angebotene Zeitpunkt. K12 vergleicht darauf: derselbe Slot
            // wird demselben Eintrag nie zweimal angeboten.
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->datetime('blocked_from');
            $table->datetime('blocked_until');

            $table->datetime('expires_at');
            $table->datetime('answered_at')->nullable();

            // **Aus der Antwort des Anbieters, nie geschaetzt.** Ein Angebot
            // ausserhalb des 24-Stunden-Fensters ist ein kostenpflichtiges
            // Template (Entscheidungen B7, B8).
            $table->unsignedBigInteger('cost_micros')->nullable();

            // Der Waechter fuer die Spalte darunter.
            //
            // **Warum eine zweite Spalte mit demselben Wert?** MySQL verbietet
            // ON DELETE CASCADE auf einer Spalte, von der eine STORED
            // generierte Spalte abhaengt (docs/datenmodell.md, 0.3). Der
            // Fremdschluessel soll aber kaskadieren, damit ein geloeschter
            // Kontakt (WP-18) seine Eintraege und deren Angebote mitnimmt.
            // Also haengt der Waechter an dieser Kopie und nicht am
            // Fremdschluessel.
            $table->binary('entry_key', 16, true);

            $table->datetimes();

            $table->index(['organization_id', 'status', 'expires_at'], 'angebot_offen_idx');
            $table->index(['waitlist_entry_id', 'starts_at'], 'angebot_slot_idx');
        });

        // **K9 als Datenbankregel** (Entscheidung A10): kein zweites offenes
        // Angebot je Eintrag. MySQL kennt keine partiellen Indizes; eine
        // generierte Spalte, die ausserhalb von 'pending' NULL ist, wird vom
        // Unique-Index nicht verglichen.
        //
        // Eine Pruefung im Code bestuenden zwei gleichzeitige Vergabelaeufe
        // beide.
        DB::statement(
            'ALTER TABLE waitlist_offers ADD COLUMN offer_guard VARBINARY(32) '
            ."GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN entry_key ELSE NULL END) STORED"
        );

        DB::statement('ALTER TABLE waitlist_offers ADD UNIQUE KEY angebot_offen_unique (organization_id, offer_guard)');
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_offers');
        Schema::dropIfExists('waitlist_entry_location');
        Schema::dropIfExists('waitlist_entries');
    }
};
