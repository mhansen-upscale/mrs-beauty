<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Freigabe per Einmal-PIN (WP-34b, C15)
|--------------------------------------------------------------------------
|
| Die PIN ist ein zweiter Weg zu derselben Freigabe wie der Klick, kein
| Generalschluessel. Gespeichert ist nur ihr Hash -- der Klartext geht genau
| einmal an die Inhaberin, die sie erzeugt hat.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_pins', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->binary('created_by_user_id', 16, true);
            $table->string('pin_hash', 255);
            $table->datetime('expires_at');

            $table->datetime('used_at')->nullable();
            $table->binary('used_by_user_id', 16, true)->nullable();

            // Die Sitzung, die mit dieser PIN Vollzugriff bekam. Faellt die
            // Sitzung, faellt die PIN mit -- ohne Sitzung sagt sie nichts mehr.
            TenantSchema::reference($table, 'impersonation_session_id', 'impersonation_sessions', nullable: true, cascadeOnDelete: true);

            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->datetime('revoked_at')->nullable();
            $table->datetimes();

            // Je Praxis hoechstens eine offene PIN. Ausserhalb des offenen
            // Zustands NULL, damit der Unique-Index sie nicht vergleicht
            // (Entscheidung A10). **Der Guard kennt die Uhr nicht** -- eine
            // abgelaufene PIN ist noch offen, bis die naechste sie widerruft.
            //
            // VIRTUAL, nicht STORED: organization_id traegt einen
            // Fremdschluessel mit ON DELETE CASCADE, und MySQL verbietet das
            // fuer Basisspalten einer STORED-Spalte.
            $table->binary('open_guard', 16, true)
                ->nullable()
                ->virtualAs('CASE WHEN used_at IS NULL AND revoked_at IS NULL THEN organization_id END');

            $table->unique('open_guard');

            $table->index(['organization_id', 'expires_at']);
        });

        Schema::table('impersonation_sessions', function (Blueprint $table): void {
            // Klick oder PIN (WP-34b). Die Freigabe selbst ist dieselbe.
            $table->string('approval_method', 16)->nullable()->after('approved_by_user_id');
        });

        // Bis hier gab es nur den Klick.
        DB::table('impersonation_sessions')
            ->whereNotNull('approved_at')
            ->update(['approval_method' => 'klick']);
    }

    public function down(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table): void {
            $table->dropColumn('approval_method');
        });

        Schema::dropIfExists('support_pins');
    }
};
