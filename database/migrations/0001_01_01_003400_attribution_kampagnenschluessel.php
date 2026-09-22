<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // **Der Schluessel darf offen liegen, die Aussage nicht.**
            //
            // Der attribution_snapshot ist verschluesselt, weil er den
            // Kampagnennamen traegt -- und damit laesst sich nicht
            // gruppieren: jede Auswertung muesste jeden Termin
            // entschluesseln.
            //
            // Die Kennung dagegen ist eine Ziffernfolge ohne Aussage. Wer sie
            // zu einem Namen aufloesen will, braucht unsere ad_campaigns, und
            // dort liegt der Name verschluesselt.
            $table->string('attribution_campaign_id', 64)->nullable()->after('attribution_snapshot');

            $table->index(['organization_id', 'attribution_campaign_id'], 'termin_kampagne_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex('termin_kampagne_idx');
            $table->dropColumn('attribution_campaign_id');
        });
    }
};
