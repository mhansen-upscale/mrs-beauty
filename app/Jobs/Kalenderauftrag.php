<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Was alle Kalenderauftraege gemeinsam haben.
 *
 * **Kanonische UUIDs in der Nutzlast, keine Rohbytes.** Der Primaerschluessel
 * ist BINARY(16) (Entscheidung A4); in einer Job-Nutzlast bricht er
 * json_encode() mit "Malformed UTF-8 characters" -- und die Meldung zeigt auf
 * die Queue statt auf die Ursache. Dieselbe Falle wie in WP-13.
 *
 * Der Mandant kommt aus der Nutzlast: ein Job laeuft ohne Anfrage und ohne
 * angemeldeten Benutzer, also ohne Mandantenkontext (Regel 1).
 *
 * Queue `sync`: ein Kalenderabgleich darf nicht vor einer Terminerinnerung
 * stehen (WP-33).
 */
abstract class Kalenderauftrag
{
    use Queueable;

    public function __construct(
        protected readonly string $verbindung,
        protected readonly string $organisation,
    ) {
        $this->onQueue('sync');

        // **Erst nach dem Commit.** Die Auftraege entstehen innerhalb der
        // Transaktion des Terminplaners; ein Arbeiter, der schneller ist als
        // der Commit, faende den Termin nicht -- und der Kalendereintrag
        // bliebe lautlos aus. Die Queue-Verbindung steht projektweit auf
        // after_commit = false, die Entscheidung gehoert also an den Auftrag.
        $this->afterCommit();
    }

    /**
     * Fuehrt den Rueckruf im Mandanten der Verbindung aus.
     *
     * @param  Closure(CalendarConnection): void  $rueckruf
     */
    protected function mitVerbindung(Closure $rueckruf, bool $auchInaktive = false): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function () use ($rueckruf, $auchInaktive): void {
            $verbindung = CalendarConnection::query()
                ->whereUuid($this->verbindung)
                ->with('practitioner')
                ->first();

            if (! $verbindung instanceof CalendarConnection) {
                return;
            }

            if (! $auchInaktive && ! $verbindung->status->istAktiv()) {
                return;
            }

            $rueckruf($verbindung);
        });
    }
}
