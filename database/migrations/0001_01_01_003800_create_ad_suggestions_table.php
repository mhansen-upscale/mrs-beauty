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
        // Anzeigenentwuerfe -- **Entwuerfe**, nicht Anzeigen.
        //
        // Es gibt keinen Zustand, in dem einer ohne Menschen hinausgeht. Der
        // Weg zu einer laufenden Anzeige fuehrt ueber WP-27.
        Schema::create('ad_suggestions', function (Blueprint $table): void {
            TenantSchema::base($table);

            // **Die Woche ist ein Schluessel.** Ohne sie erzeugt jeder Lauf
            // neue Entwuerfe, und die Praxis ertrinkt darin.
            $table->date('week');

            // Verschluesselt: Anzeigentexte nennen die beworbene Leistung
            // (C9 erlaubt das), und das ist eine Behandlungsbezeichnung.
            $table->binary('headline');
            $table->binary('body');
            $table->binary('description')->nullable();
            $table->string('cta', 64)->nullable();

            $table->string('status', 16)->default('draft');

            // Welches Modell den Text geschrieben hat -- ein Vorschlag von
            // heute laesst sich sonst spaeter nicht einordnen.
            $table->string('model', 64)->nullable();

            // Bilderzeugung: erst auf Anforderung, und dann mit Auftragstext
            // und Modell (C10).
            $table->binary('image_prompt')->nullable();
            $table->string('image_model', 64)->nullable();
            $table->datetime('image_requested_at')->nullable();

            $table->datetimes();

            $table->index(['organization_id', 'week'], 'vorschlag_woche_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_suggestions');
    }
};
