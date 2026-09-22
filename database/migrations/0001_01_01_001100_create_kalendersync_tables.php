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
        // Ein Behandler, ein Kalender, ein Anbieter.
        //
        // Der Anbieter steht als Spalte und nicht als eigene Tabelle: WP-15
        // ergaenzt einen Fall, nicht ein Schema. Ein gemeinsames Interface
        // entsteht bewusst erst danach -- docs/integrationen/kalender.md,
        // "Erst beide umsetzen, dann abstrahieren".
        Schema::create('calendar_connections', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);

            $table->string('provider', 16);
            $table->string('status', 16)->default('active');
            $table->string('privacy_mode', 16)->default('busy_only');

            // Zugangsdaten und Kalenderkennung liegen verschluesselt (Regel 3,
            // Entscheidung A6). Die Kalenderkennung ist bei Google in aller
            // Regel eine E-Mail-Adresse und damit selbst personenbezogen.
            $table->binary('calendar_id');
            $table->binary('account_email')->nullable();
            $table->binary('access_token')->nullable();
            $table->binary('refresh_token')->nullable();
            $table->datetime('access_expires_at')->nullable();

            // Die Zone des Kalenders, einmal beim Verbinden abgefragt.
            // Ganztaegige Events kommen ohne Zone -- ohne diese Spalte
            // muesste man eine annehmen, und genau das verbietet
            // docs/integrationen/kalender.md.
            $table->string('calendar_timezone', 64)->default('UTC');

            // Der Delta-Zeiger. Undurchsichtig, ohne Personenbezug, deshalb
            // im Klartext -- er wird gelesen und geschrieben, nie gesucht.
            $table->text('sync_token')->nullable();

            // Das Abonnement (Watch-Channel). Die Kanalkennung ist
            // **mandantenuebergreifend** eindeutig: die Zustellung kommt ohne
            // Anmeldung an und muss den Mandanten erst finden.
            $table->uuid('channel_id')->nullable()->unique();
            $table->string('channel_resource_id', 191)->nullable();
            $table->string('channel_token', 64)->nullable();
            $table->datetime('channel_expires_at')->nullable();

            $table->datetime('last_synced_at')->nullable();

            // Ein Kurzgrund, niemals eine Fehlermeldung mit Personenbezug.
            $table->string('last_error', 64)->nullable();
            $table->datetime('failed_at')->nullable();

            $table->datetimes();

            // Ein Behandler hat je Anbieter hoechstens eine Verbindung. Zwei
            // Verbindungen auf denselben Kalender erzeugten doppelte Blocker.
            $table->unique(['practitioner_id', 'provider'], 'kalender_behandler_anbieter_unique');

            // Der Erneuerungsjob sucht genau danach.
            $table->index(['status', 'channel_expires_at'], 'kalender_ablauf_idx');
        });

        // Was von aussen kommt.
        //
        // **Diese Tabelle hat keine Titelspalte, und das ist die Umsetzung von
        // R2.** Uebernommen wird ausschliesslich der Zeitraum. Was es nicht
        // gibt, kann niemand spaeter "nur zur Anzeige" befuellen.
        Schema::create('external_calendar_blocks', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'calendar_connection_id', 'calendar_connections', cascadeOnDelete: true);

            // Verdoppelt aus der Verbindung: der Blockerabgleich laeuft ueber
            // Behandler und Zeitraum und soll dafuer nicht joinen muessen.
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);

            // Die Kennung des Events beim Anbieter. Undurchsichtig und ohne
            // Personenbezug -- sie traegt die Deduplizierung (A14).
            $table->string('external_id', 191);

            $table->datetime('starts_at');
            $table->datetime('ends_at');

            // Ganztaegig heisst: die Zeiten sind aus der Kalenderzone
            // gerechnet, nicht angenommen.
            $table->boolean('is_all_day')->default(false);

            $table->datetimes();

            $table->unique(['calendar_connection_id', 'external_id'], 'kalenderblocker_extern_unique');
            $table->index(['practitioner_id', 'starts_at'], 'kalenderblocker_behandler_idx');
        });

        // Was nach aussen geht.
        //
        // Die Zeile ist zugleich der Idempotenzschluessel (Entscheidung A13):
        // liegt eine externe Kennung vor, wird aktualisiert statt angelegt.
        // Zwei Laeufe desselben Auftrags erzeugen damit ein Event, nicht zwei.
        Schema::create('calendar_event_links', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'calendar_connection_id', 'calendar_connections', cascadeOnDelete: true);
            TenantSchema::reference($table, 'appointment_id', 'appointments', cascadeOnDelete: true);

            $table->string('external_event_id', 191)->nullable();
            $table->datetime('synced_at')->nullable();

            // Extern geloescht, nicht bei uns: R3. Der Termin bleibt bestehen
            // und das Event wird beim naechsten Ausgangslauf neu geschrieben.
            $table->datetime('removed_at')->nullable();

            $table->datetimes();

            $table->unique(['appointment_id', 'calendar_connection_id'], 'kalenderlink_termin_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('external_calendar_blocks');
        Schema::dropIfExists('calendar_connections');
    }
};
