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
        // **Ein Vorgang je Konversation** (Entscheidung G9).
        //
        // Der Unique-Index ist der Idempotenzschluessel aus der
        // Spezifikation: "Je Konversation ist genau eine Buchung in Arbeit."
        // Ein zweiter Buchungsversuch findet diese Zeile und fuehrt zur
        // Rueckfrage -- nicht zu einem zweiten Termin.
        Schema::create('agent_dialogs', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'conversation_id', 'conversations', cascadeOnDelete: true);

            $table->string('state', 32);

            // Schritt 6: hoechstens drei Klaerungsversuche **je Zustand**.
            // Der Zaehler gehoert deshalb zum Zustand und wird beim Wechsel
            // zurueckgesetzt.
            $table->unsignedTinyInteger('attempts')->default(0);

            TenantSchema::reference($table, 'treatment_id', 'treatments', nullable: true);
            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types', nullable: true);
            TenantSchema::reference($table, 'location_id', 'locations', nullable: true);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', nullable: true);
            TenantSchema::reference($table, 'slot_hold_id', 'slot_holds', nullable: true);
            TenantSchema::reference($table, 'appointment_id', 'appointments', nullable: true);

            // **Verschluesselt** (Regel 3): der Name der Person und die
            // Zeitpunkte, die ihr angeboten wurden.
            $table->binary('name')->nullable();
            $table->binary('offered')->nullable();

            $table->datetime('consent_at')->nullable();
            $table->datetimes();

            $table->unique(['organization_id', 'conversation_id'], 'agentendialog_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_dialogs');
    }
};
