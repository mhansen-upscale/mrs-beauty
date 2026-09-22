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
        // **WP-11 besitzt diese Tabelle.** Hier entsteht nur, was der Slot
        // braucht, damit der Fremdschluessel steht -- ein Slot, der auf eine
        // Terminnummer ohne Tabelle zeigt, waere genau die Art von loser
        // Verbindung, die dieses Projekt sonst vermeidet (Entscheidung A2).
        Schema::create('appointments', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types');
            TenantSchema::reference($table, 'practitioner_id', 'practitioners');
            TenantSchema::reference($table, 'location_id', 'locations');

            // Was dem Kontakt angezeigt wird -- ohne Ruestzeit.
            $table->datetime('starts_at');
            $table->datetime('ends_at');

            // Was im Kalender belegt ist -- mit Ruestzeit.
            $table->datetime('blocked_from');
            $table->datetime('blocked_until');

            $table->string('status', 32);
            $table->datetimes();

            $table->index(['organization_id', 'starts_at']);
            $table->index(['practitioner_id', 'starts_at']);
        });

        Schema::create('slot_holds', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types');
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->string('purpose', 32);

            $table->datetime('blocked_from');
            $table->datetime('blocked_until');

            // Ein abgelaufener Hold gilt **sofort** als abgelaufen. Jede
            // Abfrage vergleicht gegen die Uhr; der Aufraeumjob gibt nur
            // Zeilen frei, er entscheidet nichts.
            $table->datetime('expires_at');
            $table->datetime('released_at')->nullable();

            $table->datetimes();

            $table->index(['organization_id', 'expires_at']);
        });

        // Die materialisierte Verfuegbarkeit (Entscheidung A9).
        //
        // Eine Zeile je Behandler und 5-Minuten-Schritt. MySQL kennt keine
        // Exclusion Constraints -- das hier ist der Ersatz, siehe
        // docs/datenmodell.md, Abschnitt 0.2.
        Schema::create('appointment_slots', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->datetime('starts_at');

            // Genau eine der drei Spalten ist gesetzt, wenn der Slot belegt
            // ist. Getrennt, weil Regel R3 aus docs/integrationen/kalender.md
            // zwischen Blockern und Terminen unterscheidet: der externe
            // Kalender gewinnt bei Blockern, das System gewinnt bei Terminen.
            TenantSchema::reference($table, 'appointment_id', 'appointments', nullable: true);
            TenantSchema::reference($table, 'slot_hold_id', 'slot_holds', nullable: true);
            $table->binary('external_block_id', 16, true)->nullable();

            // **Der eigentliche Doppelbuchungsschutz.** Ein Behandler kann
            // nicht zweimal zur selben Zeit da sein -- und auch nicht an zwei
            // Orten. Damit wird Doppelvergabe ein Datenbankfehler statt eines
            // Fachfehlers, den niemand bemerkt.
            $table->unique(['practitioner_id', 'starts_at'], 'slots_behandler_zeit_unique');

            // Die Abfrage freier Strecken laeuft immer ueber diese drei.
            $table->index(['organization_id', 'starts_at']);
            $table->index(['location_id', 'starts_at']);
            $table->index('slot_hold_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_slots');
        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('appointments');
    }
};
