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
        // **WP-16 besitzt diese Tabelle.** Hier entsteht nur, was ein Termin
        // verlangt: wer kommt, und wie erreicht man die Person. Kanaele,
        // Einwilligungen, Leads und das Zusammenfuehren gehoeren dorthin.
        //
        // `contacts`, nicht `patients` (Entscheidung D1). Der Name haelt die
        // Grenze im Code sichtbar: dieses Produkt fuehrt keine Patientenakte
        // (P1), es fuehrt einen Terminkalender.
        Schema::create('contacts', function (Blueprint $table): void {
            TenantSchema::base($table);

            // Verschluesselt, deshalb binaer und ohne Laengenbegrenzung
            // (Regel 3, Entscheidung A6).
            $table->binary('first_name');
            $table->binary('last_name');
            $table->binary('email')->nullable();
            $table->binary('phone')->nullable();

            // Blinde Indizes: HMAC-SHA-256, also immer 32 Byte.
            //
            // Nur E-Mail und Nachname. Eine Telefonnummer braucht vorher eine
            // E.164-Normalisierung -- "+49 170 1234567" und "01701234567"
            // ergaeben sonst zwei verschiedene Hashes und die Suche traefe
            // still nie. Die Normalisierung gehoert zu WP-16, siehe
            // App\Support\BlindIndex.
            $table->binary('email_bidx', 32, true)->nullable()->index();
            $table->binary('last_name_bidx', 32, true)->nullable()->index();

            // Entscheidung A12: kein Soft Delete. Eine Loeschanfrage muss
            // echt loeschen.
            $table->datetimes();
        });

        // WP-10 hat `appointments` im Mindestumfang angelegt, damit der
        // Fremdschluessel der Slots steht. Hier kommt der Rest dazu.
        Schema::table('appointments', function (Blueprint $table): void {
            TenantSchema::reference($table, 'contact_id', 'contacts');

            // Wie der Termin zustande kam. Die Auswertung in WP-32 will
            // wissen, ob der Agent gebucht hat oder der Empfang.
            $table->string('booked_via', 32)->default('internal');

            // Wurde beim Anlegen die Verfuegbarkeit uebersteuert? Das erklaert
            // einen Termin ausserhalb der Arbeitszeit, ohne dass jemand im
            // Protokoll nachsehen muss.
            //
            // **Bewusst ohne Begruendungsfeld.** Ein Freitext am Termin fuellt
            // sich mit Behandlungsverlaeufen; Entscheidung P1 schliesst das
            // aus. Wer es getan hat, steht im Protokoll.
            $table->boolean('is_override')->default(false);

            $table->datetime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 32)->nullable();

            // Die Lueckenfuellung der Warteliste (WP-25) sucht genau danach:
            // was ist gerade frei geworden?
            $table->index(['organization_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign('appointments_contact_id_fk');
            $table->dropIndex(['organization_id', 'cancelled_at']);
            $table->dropColumn([
                'contact_id',
                'booked_via',
                'is_override',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });

        Schema::dropIfExists('contacts');
    }
};
