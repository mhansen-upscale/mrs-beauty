<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Protokolliert Anlegen, Aendern und Loeschen eines Modells.
 *
 * **Feldnamen, keine Werte** (Entscheidung C5). Ein Modell darf einzelne
 * Felder als unbedenklich erklaeren, indem es auditableValues() ueberschreibt
 * -- die Vorgabe ist, nichts mitzuschreiben. Das ist die richtige Richtung:
 * wer etwas ins Protokoll aufnimmt, soll das begruenden muessen, nicht
 * umgekehrt.
 *
 * @mixin Model
 */
trait Auditable
{
    /**
     * Felder, deren **Werte** ins Protokoll duerfen.
     *
     * Nur fuer Felder ohne Personenbezug -- Status, Rolle, Schalter.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return [];
    }

    public static function bootAuditable(): void
    {
        static::created(function (Model $modell): void {
            self::protokolliere(AuditEvent::Created, $modell, array_keys($modell->getAttributes()));
        });

        static::updated(function (Model $modell): void {
            self::protokolliere(AuditEvent::Updated, $modell, array_keys($modell->getChanges()));
        });

        static::deleted(function (Model $modell): void {
            self::protokolliere(AuditEvent::Deleted, $modell, []);
        });
    }

    /**
     * @param  array<int, string>  $felder
     */
    private static function protokolliere(AuditEvent $ereignis, Model $modell, array $felder): void
    {
        /** @var list<string> $erlaubt */
        $erlaubt = method_exists($modell, 'auditableValues') ? $modell->auditableValues() : [];

        $kontext = [];

        foreach ($erlaubt as $feld) {
            if (in_array($feld, $felder, true)) {
                $wert = $modell->getAttribute($feld);

                $kontext[$feld] = $wert instanceof \BackedEnum ? $wert->value : $wert;
            }
        }

        app(AuditLogger::class)->record(
            ereignis: $ereignis,
            gegenstand: $modell,
            geaenderteFelder: array_values(array_filter(
                $felder,
                // Zeitstempel und Rohbytes sagen nichts und blaehen das
                // Protokoll auf.
                fn (string $feld): bool => ! in_array($feld, ['id', 'created_at', 'updated_at', 'organization_id'], true)
            )),
            kontext: $kontext,
        );
    }
}
