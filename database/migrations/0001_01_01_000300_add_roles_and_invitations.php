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
        Schema::table('users', function (Blueprint $table): void {
            // VARCHAR statt MySQL-ENUM (Entscheidung A11). Der gueltige
            // Wertebereich steht in App\Enums\Role, nicht im Schema.
            $table->string('role', 32)->nullable()->after('organization_id');

            // Wer deaktiviert ist, kommt weder herein noch bleibt er drin.
            $table->datetime('deactivated_at')->nullable()->after('email_verified_at');

            // Ziel fuer zusammengesetzte Fremdschluessel spaeterer Pakete --
            // practitioners.user_id in WP-08 braucht es.
            $table->unique(['id', 'organization_id']);

            $table->index(['organization_id', 'role']);
        });

        Schema::create('invitations', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('email');
            $table->string('role', 32);

            // Nur der Hash. Wer die Datenbank liest, koennte sonst jede offene
            // Einladung annehmen.
            $table->string('token_hash', 64)->unique();

            $table->binary('invited_by_user_id', 16, true)->nullable();

            $table->datetime('expires_at');
            $table->datetime('accepted_at')->nullable();
            $table->datetime('revoked_at')->nullable();

            $table->datetimes();

            $table->foreign('invited_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Je Organisation hoechstens eine offene Einladung je Adresse.
            // Ausserhalb des offenen Zustands NULL, damit der Unique-Index
            // sie nicht vergleicht (Entscheidung A10).
            //
            // VIRTUAL, nicht STORED: organization_id traegt einen
            // Fremdschluessel mit ON DELETE CASCADE, und MySQL verbietet das
            // fuer Basisspalten einer STORED-Spalte.
            $table->string('open_guard', 255)
                ->nullable()
                ->virtualAs(
                    "CASE WHEN accepted_at IS NULL AND revoked_at IS NULL
                          THEN CONCAT(HEX(organization_id), ':', LOWER(email))
                     END"
                );

            $table->unique('open_guard');

            $table->index(['organization_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'role']);
            $table->dropUnique(['id', 'organization_id']);
            $table->dropColumn(['role', 'deactivated_at']);
        });
    }
};
