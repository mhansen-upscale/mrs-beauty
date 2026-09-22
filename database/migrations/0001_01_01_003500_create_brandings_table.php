<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use App\Support\Uuid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Das Erscheinungsbild der **Buchungsseite** -- nicht des
        // Admin-Bereichs.
        //
        // `docs/design/farben.md`: "Das Produkt hat zwei Oberflaechen mit
        // unterschiedlichen Anforderungen. Sie zu vermischen ist der Fehler,
        // der Whitelabel-Produkte kaputt macht." Der Admin-Bereich bleibt
        // Petrol, gleich was hier steht.
        Schema::create('brandings', function (Blueprint $table): void {
            TenantSchema::base($table);

            // Als Hex, wie eingetragen. Die Ableitung auf Tokens macht
            // App\Support\Markenstil beim Ausliefern -- gespeichert wird,
            // was die Praxis gewaehlt hat, nicht was daraus wurde.
            $table->string('primary_color', 7)->nullable();

            // **Die Buchungsseite ist eine oeffentliche Website** und damit
            // impressumspflichtig. Beides sind Seiten der Praxis, unter ihrer
            // Domain -- wir zeigen sie nur.
            $table->string('imprint_url', 255)->nullable();
            $table->string('privacy_url', 255)->nullable();

            $table->datetimes();

            $table->unique('organization_id', 'erscheinungsbild_unique');
        });

        // Der Wert aus organizations.settings zieht um.
        //
        // **Zwei Orte fuer dieselbe Farbe waeren zwei Farben**, sobald jemand
        // einen davon aendert -- dasselbe Muster wie bei agent_budgets in
        // WP-06.
        foreach (DB::table('organizations')->select('id', 'settings')->get() as $praxis) {
            $einstellungen = json_decode((string) $praxis->settings, true);
            $farbe = data_get(is_array($einstellungen) ? $einstellungen : [], 'branding.primary_color');

            if (! is_string($farbe) || $farbe === '') {
                continue;
            }

            DB::table('brandings')->insert([
                'id' => Uuid::generate(),
                'organization_id' => $praxis->id,
                'primary_color' => mb_substr($farbe, 0, 7),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $einstellungen = is_array($einstellungen) ? $einstellungen : [];
            unset($einstellungen['branding']);

            DB::table('organizations')
                ->where('id', $praxis->id)
                ->update(['settings' => json_encode($einstellungen)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brandings');
    }
};
