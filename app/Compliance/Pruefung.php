<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\Ampel;
use App\Models\ComplianceCheck;
use App\Models\ComplianceRuleset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Die HWG-Pruefung -- das Differenzierungsmerkmal des Produkts.
 *
 * **Sie laeuft vor jeder Veroeffentlichung**, nicht danach
 * (docs/integrationen/meta.md: "laeuft **vor** jeder Uebermittlung an Meta").
 * Ein Kunde, der eine Anzeige baut und erst von Meta erfaehrt, dass sie nicht
 * geht, haelt das Produkt fuer kaputt -- und im Fall des Bildverbots kostet
 * es bis zu 50.000 Euro.
 *
 * **Das Produkt ist eine Pruefhilfe, keine Rechtsberatung.** Formulierung und
 * Haltung spiegeln das durchgaengig: die Ampel sagt, was aufgefallen ist, und
 * nennt die Fundstelle -- sie spricht kein Urteil.
 */
final class Pruefung
{
    public function __construct(private readonly Regelwerk $regelwerk) {}

    public function pruefe(Pruefgegenstand $gegenstand, ?CarbonImmutable $jetzt = null): Pruefergebnis
    {
        $jetzt ??= CarbonImmutable::now();
        $fassung = ComplianceRuleset::geltend($jetzt);

        if (! $fassung instanceof ComplianceRuleset) {
            // **Kein Regelwerk, keine Freigabe.** Dieselbe Richtung wie bei
            // der Virenpruefung in WP-33: was niemand geprueft hat, wird
            // nicht weitergereicht.
            throw new RuntimeException('Es ist kein geltendes HWG-Regelwerk hinterlegt.');
        }

        $befunde = [];

        foreach ($this->regelwerk->regeln() as $regel) {
            foreach ($regel->pruefe($gegenstand) as $befund) {
                $befunde[] = $befund;
            }
        }

        $ampel = Ampel::Gruen;

        foreach ($befunde as $befund) {
            if ($befund->ampel->schaerferAls($ampel)) {
                $ampel = $befund->ampel;
            }
        }

        return new Pruefergebnis(
            ampel: $ampel,
            befunde: $befunde,
            version: $fassung->version,
            rechtsstand: $fassung->legal_as_of,
            geprueftAm: $jetzt,
            regelwerkGeprueft: $fassung->juristischGeprueft(),
        );
    }

    /**
     * Prueft und haelt das Ergebnis am Gegenstand fest.
     *
     * **Kein Vorschlag erreicht die Veroeffentlichung ohne vorherige
     * Pruefung** -- und ohne festgehaltenes Ergebnis laesst sich das nicht
     * zeigen.
     */
    public function pruefeUndHalteFest(Model $gegenstand, Pruefgegenstand $inhalt, ?CarbonImmutable $jetzt = null): ComplianceCheck
    {
        $ergebnis = $this->pruefe($inhalt, $jetzt);

        $pruefung = new ComplianceCheck;
        $pruefung->checkable_type = $gegenstand::class;
        $pruefung->checkable_id = $gegenstand->getKey();
        $pruefung->ruleset_version = $ergebnis->version;
        $pruefung->legal_as_of = $ergebnis->rechtsstand;
        $pruefung->checked_at = $ergebnis->geprueftAm;
        $pruefung->result = $ergebnis->ampel;
        // Wofuer das Ergebnis gilt: dieser Text, nicht der Datensatz.
        $pruefung->content_hash = self::fingerabdruck($inhalt);
        $pruefung->findings = (string) json_encode(
            array_map(fn (Befund $b): array => $b->toArray(), $ergebnis->befunde)
        );
        $pruefung->save();

        return $pruefung;
    }

    /**
     * Der Fingerabdruck eines Pruefgegenstands.
     *
     * Wer spaeter wissen will, ob eine Pruefung noch dem steht, was jetzt
     * veroeffentlicht wuerde, vergleicht ihn -- nicht den Zeitstempel.
     */
    public static function fingerabdruck(Pruefgegenstand $inhalt): string
    {
        return hash('sha256', $inhalt->gesamttext().($inhalt->hatBild ? "\0bild" : ''), true);
    }
}
