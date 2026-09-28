<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Der Versand der Plattform (WP-37, B23) -- **global**, ohne
        // organization_id, wie plan_versions: Er gehoert dem Betreiber, nicht
        // einer Praxis.
        //
        // **Genau eine Zeile.** `singleton` ist eindeutig und kann nur 1
        // sein; eine zweite Einstellung gaebe es sonst irgendwann, und
        // niemand wuesste, welche gilt.
        Schema::create('platform_mail_settings', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();
            $table->unsignedTinyInteger('singleton')->default(1)->unique();

            // Der Server. Benutzername und Passwort mit dem App-Schluessel
            // verschluesselt (Cast `encrypted`) -- der Schluessel je
            // Organisation (A5) gibt es hier nicht.
            $table->string('smtp_host', 191)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 8)->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();

            // **Der Absender gehoert zum Server.** Mit dem hinterlegten gilt
            // diese Adresse, mit .env die aus MAIL_FROM_* -- sonst scheitert
            // SPF.
            $table->string('from_address', 191)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->string('reply_to_address', 191)->nullable();

            // Das Aussehen der Produktmails -- gilt, gleich welcher Server
            // verschickt.
            $table->string('accent_color', 7)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_mime', 32)->nullable();
            $table->unsignedInteger('logo_version')->default(0);
            $table->text('footer_text')->nullable();
            $table->string('imprint_url', 255)->nullable();
            $table->string('privacy_url', 255)->nullable();

            // **Gespeichert ist nicht geprueft.** Jede Aenderung an Server
            // oder Zugangsdaten zaehlt die Fassung hoch; der Server gilt erst,
            // wenn die Probemail ueber genau diese Fassung ging.
            $table->unsignedInteger('smtp_version')->default(1);
            $table->unsignedInteger('smtp_verified_version')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('last_error', 64)->nullable();

            $table->binary('updated_by_user_id', 16, true)->nullable();
            $table->datetimes();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE platform_mail_settings ADD CONSTRAINT plattformversand_einmal CHECK (singleton = 1)');
        }

        // Die Vorlagen der Produktmails -- Ueberschreibungen wie bei der
        // Praxis (D15), fuer alle Praxen zugleich.
        Schema::create('platform_mail_templates', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();
            $table->string('template', 40)->unique();

            $table->string('subject', 200);
            $table->string('greeting', 200)->default('');
            $table->text('intro');
            $table->text('outro');
            $table->string('salutation', 300)->default('');

            $table->binary('updated_by_user_id', 16, true)->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_templates');
        Schema::dropIfExists('platform_mail_settings');
    }
};
