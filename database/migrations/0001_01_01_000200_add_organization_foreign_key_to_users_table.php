<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nachgetragen, weil users vor organizations angelegt wird: die Anmeldung
     * muss funktionieren, bevor ein Mandant bekannt ist.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
        });
    }
};
