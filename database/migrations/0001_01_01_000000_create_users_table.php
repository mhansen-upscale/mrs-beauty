<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Entscheidung A7: alle Zeiten UTC als DATETIME, niemals TIMESTAMP.
    // TIMESTAMP endet 2038 und konvertiert in MySQL implizit die Zeitzone.
    // Durchgesetzt durch tests/Feature/Schema/ZeitspaltenTest.php.
    //
    // Entscheidung A4: UUID v7 als BINARY(16).
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();

            // Nullable, weil der Super-Admin aus WP-34 zu keiner Organisation
            // gehoert und weil die Anmeldung stattfindet, bevor ein Mandant
            // bekannt ist. Der Fremdschluessel wird nachgetragen, sobald die
            // Tabelle organizations existiert.
            $table->binary('organization_id', 16, true)->nullable()->index();

            $table->string('name');
            $table->string('email')->unique();
            $table->datetime('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->datetimes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->datetime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->binary('user_id', 16, true)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
