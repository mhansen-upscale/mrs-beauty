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
            // **Ein Fehlschlag gehoert ins Produkt, nicht nur ins Log**
            // (Regel 4). Das Erzeugen laeuft in der Warteschlange; ohne
            // dieses Feld erfaehrt niemand, warum die Grafik ausbleibt.
            $table->string('image_error', 255)->nullable()->after('image_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_suggestions', function (Blueprint $table): void {
            $table->dropColumn('image_error');
        });
    }
};
