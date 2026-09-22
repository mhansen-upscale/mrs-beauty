<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Anzeigen\BildNichtErzeugt;
use App\Anzeigen\Vorschlagslauf;
use App\Models\AdSuggestion;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Erzeugt die Grafik zu einem Anzeigenentwurf.
 *
 * **Nie im Anfragezyklus** (Regel 4). Das Bildmodell braucht ein bis drei
 * Minuten; ein Browser, der so lange auf eine Antwort wartet, laeuft in den
 * Zeitablauf des Webservers, und die Praxis sieht einen Fehler, obwohl die
 * Grafik gerade entsteht. Genau das ist am 20.09.2026 passiert.
 *
 * **Ein Versuch, kein zweiter.** Der Auftrag kostet Geld, sobald er beim
 * Anbieter liegt. Ein Wiederholungslauf zahlte ein zweites Mal fuer dieselbe
 * Grafik -- deshalb `tries = 1` und der Grund am Entwurf statt einer
 * Wiedervorlage.
 */
final class AnzeigenbildErzeugen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Das Bildmodell darf sich Zeit lassen -- der Mensch wartet nicht mit. */
    public int $timeout = 600;

    public function __construct(
        private readonly string $organisation,
        private readonly string $vorschlag,
        private readonly string $benutzer,
    ) {
        $this->onQueue('maintenance');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->vorschlag;
    }

    public function handle(TenantContext $mandant, Vorschlagslauf $lauf): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($lauf): void {
            $vorschlag = AdSuggestion::query()->whereUuid($this->vorschlag)->first();
            $benutzer = User::query()->whereUuid($this->benutzer)->first();

            if (! $vorschlag instanceof AdSuggestion || ! $benutzer instanceof User) {
                return;
            }

            try {
                $lauf->erzeugeBild($vorschlag, $benutzer);
            } catch (BildNichtErzeugt $fehler) {
                $vorschlag->image_error = mb_substr($fehler->getMessage(), 0, 255);
                $vorschlag->save();
            }
        });
    }
}
