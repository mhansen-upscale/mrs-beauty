<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Enums\SubscriptionAccess;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Was das Abo einer Praxis ihr gerade erlaubt -- **nur lesend** (WP-34c).
 *
 * Die Frage stellen die Mittelschicht bei jeder Anfrage, der Agent bei jeder
 * Nachricht, die Buchungsseite und der Wochenlauf der Anzeigen. Keiner davon
 * soll eine Abo-Zeile anlegen, wie Kontingente::abo() es tut: ein
 * Schreibvorgang je Seitenaufruf waere falsch.
 *
 * **Ohne Abo-Zeile ist eine Praxis in der Testphase** -- gerechnet ab ihrem
 * Anlegen (B18), nicht ab dem Zeitpunkt, an dem zufaellig jemand die Zeile
 * anlegt.
 */
final class Abozugang
{
    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Paket $paket,
    ) {}

    public function fuer(Organization $praxis, ?CarbonImmutable $jetzt = null): SubscriptionAccess
    {
        $jetzt ??= CarbonImmutable::now();

        $abo = $this->mandant->runAs($praxis, fn (): ?Subscription => Subscription::query()->first());

        if ($abo instanceof Subscription) {
            return $abo->zugang($jetzt);
        }

        /** @var CarbonImmutable $angelegt */
        $angelegt = $praxis->getAttribute('created_at');

        return $angelegt->addDays($this->paket->aktuell()->trial_days)->greaterThan($jetzt)
            ? SubscriptionAccess::Trial
            : SubscriptionAccess::TrialExpired;
    }

    /** Die Praxis des geltenden Mandanten -- ohne Mandanten gibt es kein Abo. */
    public function jetzt(?CarbonImmutable $jetzt = null): ?SubscriptionAccess
    {
        $praxis = $this->mandant->current();

        return $praxis instanceof Organization ? $this->fuer($praxis, $jetzt) : null;
    }
}
