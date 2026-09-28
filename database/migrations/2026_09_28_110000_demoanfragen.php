<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Die Demo-Anfragen der Startseite (WP-38) -- **global**, ohne
        // organization_id, wie platform_mail_settings: Wer anfragt, ist noch
        // keine Praxis.
        //
        // **Kein Freitextfeld** (D2): ein "Ihre Nachricht" fuellt sich auf einer
        // oeffentlichen Seite mit Behandlungswuenschen. Die fuenf Angaben
        // liegen mit dem App-Schluessel verschluesselt (Cast `encrypted`) --
        // deshalb `text`, der Geheimtext ist laenger als die Eingabe.
        Schema::create('demo_requests', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();

            $table->text('name');
            $table->text('practice_name');
            $table->text('email');
            $table->text('phone')->nullable();
            $table->text('city')->nullable();

            // A11: Status als VARCHAR plus Enum.
            $table->string('status', 16)->default('new');
            $table->dateTime('status_changed_at')->nullable();

            $table->datetimes();

            // Die Liste im Backoffice (neueste zuerst, nach Status) und die
            // Aufbewahrung (nach Eingang).
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
