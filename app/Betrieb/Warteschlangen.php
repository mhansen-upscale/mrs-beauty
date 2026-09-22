<?php

declare(strict_types=1);

namespace App\Betrieb;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Support\Str;

/**
 * Laeuft ueberhaupt jemand die Warteschlangen ab?
 *
 * **Diese Klasse ersetzt einen Test, den es nicht mehr geben kann.** Solange
 * Horizon die Arbeiter startete, stand ihre Zahl in `config/horizon.php` und
 * war pruefbar. Auf der verwalteten Warteschlange von Laravel Cloud liegt die
 * Arbeiterdefinition in der Oberflaeche des Anbieters -- ausserhalb des
 * Repositorys und ausserhalb jeder Testsuite.
 *
 * Am 22.09.2026 war genau das der Ausfall: `QUEUE_CONNECTION=cloud`, aber
 * kein Arbeiter darauf. Jeder Auftrag wurde angenommen und blieb liegen. Kein
 * Fehler, keine Meldung, nichts im Protokoll -- bis eine Praxis fragte, warum
 * keine Grafik entsteht.
 *
 * **Tiefe allein ist kein Alarm, Stille allein auch nicht.** Eine leere
 * Warteschlange darf tagelang ruhen, und eine gefuellte darf eine Minute
 * brauchen. Erst beides zusammen heisst: da holt niemand mehr ab.
 */
final class Warteschlangen
{
    public function __construct(
        private readonly Factory $warteschlange,
        private readonly Repository $zwischenspeicher,
    ) {}

    /**
     * Haelt fest, dass auf dieser Warteschlange gerade gearbeitet wurde.
     *
     * Gerufen aus `Queue::after` -- einmal je verarbeitetem Auftrag. Der
     * Rohname kommt vom Treiber und wird auf den nackten Namen gekuerzt.
     */
    public function vermerkeLauf(string $warteschlange, ?CarbonImmutable $jetzt = null): void
    {
        $name = $this->name($warteschlange);

        if (! array_key_exists($name, $this->profile())) {
            return;
        }

        $this->zwischenspeicher->forever(
            $this->schluessel($name),
            ($jetzt ?? CarbonImmutable::now())->toIso8601String(),
        );
    }

    /**
     * Die Warteschlangen, auf denen etwas liegt und niemand mehr abholt.
     *
     * @return list<string>
     */
    public function stehende(?CarbonImmutable $jetzt = null): array
    {
        $stehende = [];

        foreach ($this->lage($jetzt) as $eintrag) {
            if ($eintrag['steht']) {
                $stehende[] = $eintrag['name'];
            }
        }

        return $stehende;
    }

    /**
     * Tiefe, letzter Lauf und Urteil je Warteschlange.
     *
     * @return list<array{name: string, tiefe: int, zuletzt: string|null, steht: bool}>
     */
    public function lage(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $lage = [];

        foreach ($this->profile() as $name => $profil) {
            $tiefe = $this->tiefe($name);
            $zuletzt = $this->zuletzt($name);

            $frist = $jetzt->subMinutes((int) ($profil['stillstand_minuten'] ?? 15));

            $lage[] = [
                'name' => $name,
                'tiefe' => $tiefe,
                'zuletzt' => $zuletzt?->toIso8601String(),

                // **Nie gelaufen zaehlt wie zu lange nicht gelaufen**, sobald
                // etwas liegt. Der schlimmste Fall -- gar kein Arbeiter --
                // hinterlaesst keinen Vermerk, und genau der darf nicht
                // durchrutschen.
                'steht' => $tiefe > 0 && (! $zuletzt instanceof CarbonImmutable || $zuletzt->lessThan($frist)),
            ];
        }

        return $lage;
    }

    /**
     * Wie viele Auftraege liegen.
     *
     * Ein Fremdsystem darf hier ausfallen, ohne eine Seite mitzunehmen
     * (Regel 4): eine unerreichbare Warteschlange meldet null statt einen
     * Fehler zu werfen.
     */
    private function tiefe(string $name): int
    {
        try {
            return (int) $this->warteschlange->connection()->size($name);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function zuletzt(string $name): ?CarbonImmutable
    {
        $wert = $this->zwischenspeicher->get($this->schluessel($name));

        return is_string($wert) ? CarbonImmutable::parse($wert) : null;
    }

    private function schluessel(string $name): string
    {
        return 'warteschlange:'.$name.':zuletzt';
    }

    /**
     * Der nackte Name, aus dem, was der Treiber meldet.
     *
     * Die Datenbank meldet `maintenance`, Redis `queues:maintenance`, SQS
     * eine vollstaendige URL. Ohne diese Kuerzung vergleicht der Vermerk
     * einen Namen, den es in der Konfiguration nicht gibt -- und die
     * Warteschlange staende fuer immer still.
     */
    private function name(string $roh): string
    {
        return Str::afterLast(Str::afterLast($roh, '/'), ':');
    }

    /** @return array<string, array<string, int>> */
    private function profile(): array
    {
        /** @var array<string, array<string, int>> $profile */
        $profile = (array) config('warteschlangen.profile', []);

        return $profile;
    }
}
