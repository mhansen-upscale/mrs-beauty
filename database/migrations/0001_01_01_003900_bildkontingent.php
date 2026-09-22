<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // **Ein eigener Zaehler** (WP-31): ein erzeugtes Bild kostet ein
            // Vielfaches eines Textlaufs. Beides in einen Topf zu werfen
            // hiesse, dass ein paar Bilder den Assistenten fuer den Rest des
            // Monats verstummen lassen -- und das trifft dann eine
            // Patientin, die auf eine Antwort wartet.
            $table->unsignedInteger('extra_images')->default(0)->after('extra_agent_runs');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('extra_images');
        });
    }
};
