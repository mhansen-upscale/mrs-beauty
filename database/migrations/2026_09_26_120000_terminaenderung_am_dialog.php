<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Termin, den der Agent absagen oder verschieben soll (offen seit WP-24).
 *
 * **Nicht `appointment_id`.** Die haelt fest, welchen Termin dieser Vorgang
 * gebucht hat, und traegt Entscheidung G9: ein zweiter Buchungsversuch fuehrt
 * zur Rueckfrage. Ein Termin, der geaendert werden soll, kann von der
 * Buchungsseite stammen oder vom Empfang -- er gehoert nicht dem Vorgang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_dialogs', function (Blueprint $table): void {
            TenantSchema::reference($table, 'change_appointment_id', 'appointments', nullable: true);
        });
    }

    public function down(): void
    {
        Schema::table('agent_dialogs', function (Blueprint $table): void {
            $table->dropForeign('agent_dialogs_change_appointment_id_fk');
            $table->dropColumn('change_appointment_id');
        });
    }
};
