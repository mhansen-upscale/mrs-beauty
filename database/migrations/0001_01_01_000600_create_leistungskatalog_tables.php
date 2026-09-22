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
        // Der Katalog. Einzige Quelle fuer Behandlungsnamen und Preise --
        // und zugleich die Sperrliste dessen, was nie an Meta gehen darf
        // (Regel 2 in CLAUDE.md).
        Schema::create('treatments', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('name');
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('category', 120)->nullable();

            // Preisspanne. Was davon oeffentlich gezeigt wird, entscheidet
            // WP-12 zusammen mit der HWG-Pruefung aus WP-30.
            $table->unsignedInteger('price_from_cents')->nullable();
            $table->unsignedInteger('price_to_cents')->nullable();

            // Entscheidung D14: beim Onboarding zu erheben, ohne diesen Wert
            // kein ROAS. Ausdruecklich eine **Schaetzung**, kein
            // abgerechneter Umsatz.
            $table->unsignedInteger('avg_revenue_cents');

            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('appointment_types', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('name');
            $table->string('slug', 120);
            $table->text('description')->nullable();

            // Nullable: Nachkontrolle und allgemeine Beratung haben keinen
            // Behandlungsbezug. Folge ist ein Umsatzwert von 0 in der
            // Auswertung -- richtig, aber sichtbar zu machen.
            TenantSchema::reference($table, 'treatment_id', 'treatments', nullable: true);

            // V11: belegt wird Ruestzeit davor + Dauer + Ruestzeit danach,
            // angezeigt wird nur die Dauer.
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);

            // V9: Untergrenze des Vorlaufs. Eine Beratung in zwei Stunden ist
            // denkbar, eine Operation nicht.
            $table->unsignedSmallInteger('lead_time_hours')->default(0);

            $table->string('color', 7)->default('#6366f1');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        // V7: welcher Behandler ist fuer diese Terminart freigegeben.
        Schema::create('appointment_type_practitioner', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types', cascadeOnDelete: true);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);

            $table->datetimes();

            $table->unique(['appointment_type_id', 'practitioner_id'], 'atp_unique');
        });

        // V8: welcher Standort bietet diese Terminart an.
        Schema::create('appointment_type_location', function (Blueprint $table): void {
            TenantSchema::base($table);
            TenantSchema::reference($table, 'appointment_type_id', 'appointment_types', cascadeOnDelete: true);
            TenantSchema::reference($table, 'location_id', 'locations', cascadeOnDelete: true);

            $table->datetimes();

            $table->unique(['appointment_type_id', 'location_id'], 'atl_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_type_location');
        Schema::dropIfExists('appointment_type_practitioner');
        Schema::dropIfExists('appointment_types');
        Schema::dropIfExists('treatments');
    }
};
