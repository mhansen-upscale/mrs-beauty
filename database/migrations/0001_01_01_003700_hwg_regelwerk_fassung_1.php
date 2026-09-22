<?php

declare(strict_types=1);

use App\Support\Uuid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('compliance_rulesets')->where('version', 1)->exists()) {
            return;
        }

        // **Fassung 1 -- ungeprueft.**
        //
        // `reviewed_by` und `reviewed_at` bleiben leer, bis ein
        // Medizinrechtler den Testsatz durchgesehen hat
        // (specs/WP-30, Abnahmekriterium 1). Solange sagt das Produkt es an
        // jeder Ampel: eine Pruefhilfe, der jemand vertraut, ohne dass sie
        // geprueft ist, ist gefaehrlicher als gar keine.
        DB::table('compliance_rulesets')->insert([
            'id' => Uuid::generate(),
            'version' => 1,
            'legal_as_of' => '2025-07-31',
            'valid_from' => '2025-07-31',
            'valid_until' => null,
            'changelog' => 'Startregelsatz aus specs/WP-30-hwg-compliance.md. '
                .'Rechtsstand: BGH I ZR 170/24 vom 31.07.2025 -- das Bildverbot gilt seither '
                .'auch fuer minimalinvasive Eingriffe.',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('compliance_rulesets')->where('version', 1)->delete();
    }
};
