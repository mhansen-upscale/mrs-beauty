<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_notifications', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'appointment_id', 'appointments', cascadeOnDelete: true);

            $table->string('kind', 32);
            $table->string('channel', 32);

            // Null bedeutet: sofort. Die Erinnerung traegt den einzigen
            // geplanten Zeitpunkt.
            $table->datetime('scheduled_for')->nullable();

            $table->datetime('sent_at')->nullable();
            $table->datetime('failed_at')->nullable();

            // Ein Kurzgrund, niemals eine Fehlermeldung mit Personenbezug --
            // diese Tabelle ist sonst die naechste unverschluesselte Kopie
            // (Entscheidung C5, sinngemaess).
            $table->string('failure', 64)->nullable();

            $table->datetimes();

            // **Genau einmal als Zusage der Datenbank.** Ein Job, der zweimal
            // laeuft, ist der Normalfall -- nach einem Deploy, nach einem
            // Neustart, bei zwei Planern. Derselbe Gedanke wie der
            // Unique-Index in WP-10: wenn die Logik versagt, gewinnt die
            // Datenbank.
            $table->unique(['appointment_id', 'kind'], 'benachrichtigung_art_unique');

            // Der Versandbefehl sucht genau danach.
            $table->index(['organization_id', 'scheduled_for', 'sent_at'], 'benachrichtigung_faellig_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notifications');
    }
};
