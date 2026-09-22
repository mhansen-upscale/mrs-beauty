<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\Organization;
use App\Tenancy\Exceptions\TenantContextMissing;
use Closure;
use InvalidArgumentException;

/**
 * Der aktuell geltende Mandant.
 *
 * Als Singleton gebunden. Gesetzt wird er von der Middleware aus dem
 * angemeldeten Benutzer, von einem Job aus seiner Nutzlast oder von einem
 * Konsolenbefehl ausdruecklich.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    /**
     * Ist der Global Scope gerade ausser Kraft?
     *
     * Nur ueber acrossTenants() zu erreichen, nie als stiller Nebeneffekt.
     * WP-05 haengt hier die Protokollierung ein, WP-34 das Backoffice.
     */
    private bool $scopeAusgesetzt = false;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function forget(): void
    {
        $this->organization = null;
    }

    public function current(): ?Organization
    {
        return $this->organization;
    }

    public function has(): bool
    {
        return $this->organization instanceof Organization;
    }

    /** Die ID des Mandanten in binaerer Form, oder null. */
    public function id(): ?string
    {
        $id = $this->organization?->getKey();

        return is_string($id) ? $id : null;
    }

    /** Wie id(), wirft aber statt null zu liefern. */
    public function requireId(string $modell = 'Mandantendaten'): string
    {
        return $this->id() ?? throw TenantContextMissing::beimZugriff($modell);
    }

    /**
     * Fuehrt den Rueckruf im Kontext einer anderen Organisation aus und stellt
     * den vorherigen Zustand danach wieder her -- auch wenn eine Ausnahme
     * fliegt.
     *
     * @template TRueckgabe
     *
     * @param  Closure(): TRueckgabe  $rueckruf
     * @return TRueckgabe
     */
    public function runAs(Organization $organization, Closure $rueckruf): mixed
    {
        $vorher = $this->organization;
        $this->organization = $organization;

        try {
            return $rueckruf();
        } finally {
            $this->organization = $vorher;
        }
    }

    /**
     * Setzt den Global Scope fuer die Dauer des Rueckrufs aus.
     *
     * Der einzige Weg an der Mandantentrennung vorbei. Er steht ausdruecklich
     * im Code, endet mit dem Aufruf -- auch bei einer Ausnahme -- und
     * **protokolliert sich selbst** (WP-05).
     *
     * Die Begruendung ist Pflicht und wandert ins Protokoll. Wer sie nicht in
     * einem Satz sagen kann, hat vermutlich keinen guten Grund.
     *
     * @template TRueckgabe
     *
     * @param  Closure(): TRueckgabe  $rueckruf
     * @return TRueckgabe
     */
    public function acrossTenants(string $begruendung, Closure $rueckruf): mixed
    {
        if (trim($begruendung) === '') {
            throw new InvalidArgumentException(
                'acrossTenants() verlangt eine Begruendung. Sie wandert ins Protokoll.'
            );
        }

        // Verschachtelte Aufrufe protokollieren nur den aeusseren: der innere
        // sagt nichts Neues, und das Protokoll soll lesbar bleiben.
        if (! $this->scopeAusgesetzt) {
            app(AuditLogger::class)->record(
                ereignis: AuditEvent::CrossTenantAccess,
                begruendung: $begruendung,
                ohneOrganisation: true,
            );
        }

        $vorher = $this->scopeAusgesetzt;
        $this->scopeAusgesetzt = true;

        try {
            return $rueckruf();
        } finally {
            $this->scopeAusgesetzt = $vorher;
        }
    }

    public function scopeIsSuspended(): bool
    {
        return $this->scopeAusgesetzt;
    }
}
