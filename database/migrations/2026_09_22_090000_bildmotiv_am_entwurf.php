<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_suggestions', function (Blueprint $table): void {
            // Das Motiv, das die Praxis sich wuenscht -- ihre Worte, nicht
            // unsere. Verschluesselt wie der Auftrag selbst: es kann die
            // beworbene Leistung nennen (C9), und das ist eine
            // Behandlungsbezeichnung (C4).
            //
            // Getrennt von image_prompt gefuehrt: der Auftrag wird bei jedem
            // Lauf neu gebaut, das Motiv bleibt.
            $table->binary('image_brief')->nullable()->after('image_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ad_suggestions', function (Blueprint $table): void {
            $table->dropColumn('image_brief');
        });
    }
};
