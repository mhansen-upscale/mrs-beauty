<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Pruefung gilt dem Text, der geprueft wurde -- nicht dem Datensatz.
 *
 * **Seit die Buchungsseite ein Pruefgegenstand ist** (WP-30, 26.09.2026)
 * reicht "die juengste Pruefung" nicht mehr: eine Behandlungsbeschreibung,
 * die nach der Pruefung am Katalog vorbei geaendert wurde, truege sonst das
 * Gruen des alten Textes. Der Zeitstempel des Datensatzes waere das falsche
 * Mass -- er springt auch, wenn jemand nur die Umsatzschaetzung aendert.
 *
 * Ein Fingerabdruck, kein Text: der Inhalt selbst steht beim Gegenstand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_checks', function (Blueprint $table): void {
            $table->binary('content_hash', 32, true)->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_checks', function (Blueprint $table): void {
            $table->dropColumn('content_hash');
        });
    }
};
