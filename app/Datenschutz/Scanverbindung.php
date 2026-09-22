<?php

declare(strict_types=1);

namespace App\Datenschutz;

/**
 * Der Weg zum Pruefdienst.
 *
 * **Eigene Schnittstelle, damit der Test keinen Dienst braucht.** Ein Test
 * gegen einen laufenden ClamAV prueft nicht dieses Produkt, sondern die
 * Einrichtung des Rechners, auf dem er laeuft -- und er ist rot, sobald
 * jemand den Dienst nicht gestartet hat.
 */
interface Scanverbindung
{
    /**
     * Schickt den Inhalt zum Pruefer und gibt dessen Antwort zurueck.
     *
     * Wirft ScannerNichtErreichbar, wenn der Dienst nicht antwortet. Eine
     * Datei ohne Antwort gilt als ungeprueft -- nicht als sauber.
     */
    public function pruefe(string $inhalt): string;
}
