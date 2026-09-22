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
        Schema::create('locations', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('name');
            $table->string('slug', 120);

            // Entscheidung A8: die Zeitzone haengt am Standort, nicht an der
            // Organisation. Eine Praxisgruppe ist **ein** Mandant mit
            // mehreren Standorten (D11), und die koennen in verschiedenen
            // Zonen liegen.
            $table->string('timezone', 64);

            $table->string('street')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('DE');

            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();

            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('practitioners', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('title', 64)->nullable();
            $table->string('first_name');
            $table->string('last_name');

            // Optional: nicht jeder Behandler meldet sich am Produkt an.
            // Zusammengesetzter Fremdschluessel, damit kein Konto einer
            // fremden Organisation verbunden werden kann (Entscheidung A2).
            TenantSchema::reference($table, 'user_id', 'users', nullable: true);

            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'last_name']);
        });

        Schema::create('practitioner_location', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->datetimes();

            $table->unique(['practitioner_id', 'location_id']);
        });

        // Bedingung V1: wiederkehrende Arbeitszeit.
        //
        // Wochentag plus **Ortszeit**, nicht UTC. Eine Arbeitszeit "montags
        // 9 bis 17 Uhr" ist keine Zeitspanne, sondern eine Regel -- in UTC
        // gespeichert stuende sie nach der Zeitumstellung eine Stunde daneben.
        Schema::create('working_hours', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            // ISO 8601: 1 = Montag.
            $table->unsignedTinyInteger('weekday');

            $table->time('starts_at');
            $table->time('ends_at');

            $table->datetimes();

            // Mehrere Fenster je Tag sind erlaubt -- die Mittagspause ist die
            // Luecke dazwischen. Zwei Fenster mit demselben Beginn waeren
            // dagegen immer ein Versehen.
            $table->unique(['practitioner_id', 'location_id', 'weekday', 'starts_at'], 'working_hours_fenster_unique');

            $table->index(['organization_id', 'weekday']);
        });

        // Bedingung V2: Abwesenheit. Absoluter Zeitraum, deshalb UTC.
        Schema::create('absences', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);

            $table->string('reason', 32);
            $table->string('note')->nullable();

            $table->datetime('starts_at');
            $table->datetime('ends_at');

            $table->datetimes();

            $table->index(['organization_id', 'starts_at', 'ends_at']);
            $table->index(['practitioner_id', 'starts_at', 'ends_at']);
        });

        // Bedingung V3: Schliesszeit des Standorts. Ebenfalls UTC.
        Schema::create('location_closures', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->string('reason', 32);
            $table->string('note')->nullable();

            $table->datetime('starts_at');
            $table->datetime('ends_at');

            $table->datetimes();

            $table->index(['organization_id', 'starts_at', 'ends_at']);
            $table->index(['location_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_closures');
        Schema::dropIfExists('absences');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('practitioner_location');
        Schema::dropIfExists('practitioners');
        Schema::dropIfExists('locations');
    }
};
