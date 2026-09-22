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
        Schema::table('locations', function (Blueprint $table): void {
            // Metas Kennung fuer den Ort dieses Standorts.
            //
            // Sie kommt aus Metas Ortssuche -- einem **lesenden** Aufruf --
            // und wird hier festgehalten, damit nicht jede Kampagne sie neu
            // holt. Ohne sie laesst sich kein Umkreis angeben, und dann
            // beginnt die Kampagne gar nicht erst.
            $table->string('meta_city_key', 64)->nullable()->after('country');
        });

        Schema::table('ad_sets', function (Blueprint $table): void {
            TenantSchema::reference($table, 'location_id', 'locations', nullable: true);

            // **Die ganze Zielgruppe dieses Produkts.** Umkreis, Alter,
            // Geschlecht -- und nichts sonst. Interessen fehlen mit Absicht:
            // "Botox" als Interesse auszuwaehlen waere eine
            // Behandlungsbezeichnung Richtung Meta (Regel 2), in einem Feld,
            // an das niemand denkt. Custom Audiences sind ohnehin aus (C8).
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->string('genders', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ad_sets', function (Blueprint $table): void {
            $table->dropForeign('ad_sets_location_id_fk');
            $table->dropColumn(['location_id', 'radius_km', 'age_min', 'age_max', 'genders']);
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('meta_city_key');
        });
    }
};
