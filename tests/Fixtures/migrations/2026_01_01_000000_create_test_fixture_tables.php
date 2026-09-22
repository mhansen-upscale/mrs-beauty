<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabellen, die es nur im Testlauf gibt.
 *
 * WP-03 baut die Mechanik, nicht das Datenmodell -- zum Nachweis braucht es
 * trotzdem echte Tabellen. Registriert wird dieser Pfad in
 * AppServiceProvider, ausschliesslich in der Umgebung 'testing'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_records', function (Blueprint $table): void {
            TenantSchema::base($table);

            // Verschluesselt, deshalb binaer und ohne Laengenbegrenzung.
            $table->binary('label');
            $table->binary('email')->nullable();

            // Blinder Index: HMAC-SHA-256, also immer 32 Byte.
            $table->binary('email_bidx', 32, true)->nullable()->index();

            $table->datetimes();
        });

        Schema::create('test_children', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'test_record_id', 'test_records');

            $table->string('title');
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_children');
        Schema::dropIfExists('test_records');
    }
};
