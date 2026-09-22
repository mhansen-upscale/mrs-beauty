<?php

declare(strict_types=1);

namespace App\Kanaele;

/**
 * Was ein Kanal koennen muss, um Statusrueckmeldungen zu lesen.
 *
 * **Getrennt von Kanaleingang**, und zwar absichtlich: eine Rueckmeldung ist
 * keine Nachricht. Sie erzeugt keinen Eintrag im Verlauf, sie legt keine
 * Konversation an, und sie oeffnet **kein** Service-Fenster -- das zaehlt ab
 * der letzten eingehenden Nachricht, und eine Zustellbestaetigung fuer etwas,
 * das wir selbst geschickt haben, ist keine.
 *
 * Ein Kanal, der keine Rueckmeldungen kennt, setzt diese Schnittstelle
 * einfach nicht um. E-Mail wird so einer sein.
 */
interface Rueckmeldungsleser
{
    /**
     * Liest aus einem Eintrag der Zustellung die enthaltenen Rueckmeldungen.
     *
     * @param  array<string, mixed>  $eintrag
     * @return list<Rueckmeldung>
     */
    public function liesRueckmeldungen(array $eintrag): array;
}
