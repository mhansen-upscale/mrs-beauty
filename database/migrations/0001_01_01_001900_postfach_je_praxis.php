<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // **Das eigene Postfach der Praxis.**
        //
        // Ohne diese Angaben geht die Post ueber den Versand der Plattform
        // hinaus -- mit der Adresse der Praxis im Absender, aber aus fremder
        // Infrastruktur. Postfaecher pruefen genau das (SPF, DKIM), und die
        // Praxis merkt es daran, dass niemand antwortet.
        //
        // Mit eigenem Postfach geht die Mail denselben Weg wie jede andere
        // Mail der Praxis. Das loest die Zustellbarkeit an der Wurzel statt
        // ueber DNS-Eintraege je Kunde.
        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->string('smtp_host', 191)->nullable()->after('sender_id');
            $table->unsignedSmallInteger('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_encryption', 16)->nullable()->after('smtp_port');

            // **Verschluesselt** (Regel 3). Zugangsdaten zu einem Postfach
            // sind Schluessel zu Personendaten -- fuer die Behandlung hier
            // laeuft das auf dasselbe hinaus (Entscheidung C4).
            $table->binary('smtp_username')->nullable()->after('smtp_encryption');
            $table->binary('smtp_password')->nullable()->after('smtp_username');

            // Wann zuletzt nachweislich eine Mail hinausging. Ein Postfach,
            // das nie geprueft wurde, sieht im Produkt genauso aus wie eines,
            // das nicht funktioniert -- und das ist der Unterschied, auf den
            // es ankommt.
            $table->datetime('verified_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_username',
                'smtp_password',
                'verified_at',
            ]);
        });
    }
};
