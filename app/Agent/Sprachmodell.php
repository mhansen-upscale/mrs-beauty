<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Was ein Sprachmodell koennen muss.
 *
 * **Eine Schnittstelle, damit der Rest des Produkts sie nicht kennt.** Der
 * Agent haengt an Absichten, am Katalog und an den Guardrails -- nicht an
 * einem Anbieter. Der Wechsel des Modells ist eine Konfigurationszeile
 * (`mrs.agent.model`), kein Umbau.
 *
 * Und sie ist die Stelle, an der sich im Test etwas Vorhersagbares
 * einsetzen laesst. Ein Test gegen ein echtes Modell prueft nicht dieses
 * Produkt, sondern dessen Tagesform.
 */
interface Sprachmodell
{
    /**
     * Stellt eine Anfrage.
     *
     * Wirft ModellNichtErreichbar, wenn der Anbieter nicht antwortet oder
     * ablehnt -- der Aufrufer haelt das am Lauf fest, und die Inbox bleibt
     * bedienbar (Regel 4).
     */
    public function frage(Anfrage $anfrage): Antwort;

    /** Ist ueberhaupt eines angebunden? */
    public function angebunden(): bool;
}
