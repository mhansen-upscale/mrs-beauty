<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * Bausteine fuer Mandantentabellen (Entscheidungen A1, A2, A4).
 *
 * Bewusst eine Klasse mit statischen Methoden und kein Blueprint-Macro:
 * Macros sind fuer die statische Analyse undurchsichtig, und diese Regeln sind
 * die, bei denen ein uebersehener Fehler ein Mandantenleck ergibt.
 */
final class TenantSchema
{
    /**
     * Primaerschluessel und Mandantenbezug einer Mandantentabelle.
     *
     * Der Unique-Index auf (id, organization_id) ist nicht redundant: er ist
     * das Ziel, auf das die zusammengesetzten Fremdschluessel der Kindtabellen
     * verweisen (Entscheidung A2). Ohne ihn liesse MySQL sie nicht zu.
     */
    public static function base(Blueprint $table): void
    {
        $table->binary('id', 16, true)->primary();
        $table->binary('organization_id', 16, true);

        $table->foreign('organization_id')
            ->references('id')
            ->on('organizations')
            ->cascadeOnDelete();

        $table->unique(['id', 'organization_id']);
    }

    /**
     * Verweis auf eine andere Mandantentabelle.
     *
     * Der Fremdschluessel ist zusammengesetzt und schliesst die
     * organization_id ein. Damit ist ein mandantenuebergreifender Verweis auf
     * Datenbankebene unmoeglich -- nicht erst in der Anwendung.
     */
    public static function reference(
        Blueprint $table,
        string $column,
        string $references,
        bool $nullable = false,
        bool $cascadeOnDelete = false,
    ): void {
        $table->binary($column, 16, true)->nullable($nullable);

        $fremdschluessel = $table->foreign([$column, 'organization_id'], self::name($table, $column))
            ->references(['id', 'organization_id'])
            ->on($references);

        if ($cascadeOnDelete) {
            $fremdschluessel->cascadeOnDelete();
        }
    }

    /**
     * Ein kurzer, vorhersagbarer Name fuer den Fremdschluessel.
     *
     * Laravel baut den Namen sonst aus Tabelle plus allen Spalten zusammen.
     * Bei einem zusammengesetzten Fremdschluessel auf einer Pivot-Tabelle
     * sprengt das MySQLs Grenze von 64 Zeichen -- und die Fehlermeldung
     * ("Identifier name is too long") sagt nichts darueber, dass die Ursache
     * eine Konvention ist und kein Fehler im Schema.
     */
    private static function name(Blueprint $table, string $column): string
    {
        $name = "{$table->getTable()}_{$column}_fk";

        if (strlen($name) <= 64) {
            return $name;
        }

        return substr($name, 0, 50).'_'.substr(md5($name), 0, 12);
    }
}
