<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Audit\ImpersonationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Ersetzt personenbezogene Werte, solange eine maskierte Impersonation laeuft
 * (Entscheidung C4).
 *
 * **Der Eingriff sitzt am Attributzugriff, nicht an der Serialisierung.** Das
 * war eine Korrektur: zuerst hing er nur in attributesToArray(), und prompt
 * lieferte der erste Controller, der sein Array von Hand baute
 * (`'name' => $mitglied->name`), die Klarnamen aus. Eine Maskierung, die man
 * an der Aufrufstelle vergessen kann, ist keine.
 *
 * Schreiben auf ein maskiertes Feld wirft. Wer den Wert nicht sehen darf, soll
 * ihn auch nicht ueberschreiben -- sonst landet im schlimmsten Fall der
 * Platzhalter in der Datenbank, weil ein Formular ihn brav zurueckgeschickt
 * hat.
 *
 * @mixin Model
 */
trait MasksPersonalData
{
    public function getAttribute($key)
    {
        $wert = parent::getAttribute($key);

        if ($wert === null || ! $this->wirdMaskiert($key)) {
            return $wert;
        }

        return $this->maskiere($key);
    }

    public function setAttribute($key, $value)
    {
        if ($this->wirdMaskiert($key)) {
            throw new RuntimeException(
                "Das Feld {$key} ist waehrend einer maskierten Impersonation nicht "
                .'aenderbar. Wer den Wert nicht sehen darf, soll ihn nicht ueberschreiben.'
            );
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $werte = parent::attributesToArray();

        foreach ($this->personalFields() as $feld) {
            if (array_key_exists($feld, $werte) && $werte[$feld] !== null && $this->wirdMaskiert($feld)) {
                $werte[$feld] = $this->maskiere($feld);
            }
        }

        return $werte;
    }

    private function wirdMaskiert(string $feld): bool
    {
        if (! in_array($feld, $this->personalFields(), true)) {
            return false;
        }

        if (! app(ImpersonationContext::class)->masks()) {
            return false;
        }

        // Der eigene Datensatz bleibt lesbar. Ein Support, dem in der
        // Seitenleiste sein eigener Name als Platzhalter entgegensieht, haelt
        // das Produkt fuer kaputt.
        return ! $this->istDerHandelnde();
    }

    private function istDerHandelnde(): bool
    {
        $angemeldet = Auth::user();

        return $angemeldet instanceof Model
            && $angemeldet::class === static::class
            && $angemeldet->getKey() === $this->getRawOriginal($this->getKeyName());
    }

    /**
     * Der Platzhalter haengt an der ID des Datensatzes, nicht an seinem Wert.
     *
     * So bleiben zwei Zeilen unterscheidbar -- eine Supportkraft muss sagen
     * koennen "die dritte von oben" --, ohne dass sich aus dem Platzhalter
     * etwas ueber den Inhalt ableiten liesse.
     */
    private function maskiere(string $feld): string
    {
        $schluessel = $this->getRawOriginal($this->getKeyName());

        // Die **letzten** vier Stellen, nicht die ersten: UUID v7 ist
        // zeitsortiert, die vorderen Bytes sind bei kurz nacheinander
        // angelegten Zeilen identisch. Genau das macht sie ununterscheidbar --
        // und Unterscheidbarkeit ist der einzige Zweck des Anhaengsels.
        $kurz = is_string($schluessel) && $schluessel !== ''
            ? mb_strtoupper(substr(bin2hex($schluessel), -4))
            : '????';

        return match (true) {
            str_contains($feld, 'email') => "maskiert-{$kurz}@maskiert.invalid",
            str_contains($feld, 'phone') => '+49 ••• ••••••',
            default => "Maskiert {$kurz}",
        };
    }
}
