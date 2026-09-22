<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Models\Contact;
use App\Models\DataSubjectRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Auskunft, Berichtigung, Loeschung -- mit Protokoll.
 *
 * **Jedes Verlangen hinterlaesst einen Vorgang**, auch und gerade die
 * Loeschung: danach gibt es den Kontakt nicht mehr, und der Vorgang ist der
 * einzige Nachweis, dass ihm entsprochen wurde. Er enthaelt Zahlen, keine
 * Daten -- ein Nachweis der Loeschung, der die geloeschten Daten mitfuehrt,
 * ist keiner.
 */
final class Betroffenenrechte
{
    public function __construct(
        private readonly Auskunft $auskunft,
        private readonly Loeschung $loeschung,
    ) {}

    /**
     * Auskunft nach Artikel 15.
     *
     * Der Vorgang haelt fest, **dass** und **wann** Auskunft erteilt wurde,
     * und wie viele Datensaetze sie umfasste. Der Export selbst wird
     * ausgeliefert und nicht aufbewahrt: eine gespeicherte Auskunft waere
     * eine zweite Kopie aller Daten der Person.
     */
    public function auskunft(Contact $kontakt, ?User $wer = null): DataSubjectRequest
    {
        $export = $this->auskunft->fuerKontakt($kontakt);

        return $this->protokolliere(
            $kontakt,
            DataSubjectRequestType::Access,
            $wer,
            $this->umfang($export),
        );
    }

    /**
     * Loeschung nach Artikel 17 -- echt, ueber alle Tabellen.
     */
    public function loeschung(Contact $kontakt, ?User $wer = null): DataSubjectRequest
    {
        // Der Vorgang entsteht **vor** der Loeschung: danach gibt es den
        // Kontakt nicht mehr, und ein Vorgang ohne Bezug waere wertlos.
        $vorgang = $this->protokolliere($kontakt, DataSubjectRequestType::Deletion, $wer, null);

        $vorgang->result = $this->loeschung->fuerKontakt($kontakt);
        $vorgang->save();

        return $vorgang;
    }

    /**
     * Berichtigung nach Artikel 16.
     *
     * Protokolliert werden die **Feldnamen**, nicht die Werte -- weder die
     * alten noch die neuen (Entscheidung C5). Wer den alten Wert aufbewahrt,
     * hat die Berichtigung nicht ausgefuehrt.
     *
     * @param  array<string, string|null>  $daten
     */
    public function berichtigung(Contact $kontakt, array $daten, ?User $wer = null): DataSubjectRequest
    {
        $erlaubt = array_intersect_key($daten, array_flip(['first_name', 'last_name', 'email', 'phone']));

        $kontakt->fill($erlaubt);
        $geaendert = array_keys($kontakt->getDirty());
        $kontakt->save();

        return $this->protokolliere(
            $kontakt,
            DataSubjectRequestType::Rectification,
            $wer,
            ['felder' => $geaendert],
        );
    }

    /**
     * Wie viele Datensaetze eine Auskunft umfasst -- ohne ihren Inhalt.
     *
     * @param  array<string, mixed>  $export
     * @return array<string, int>
     */
    private function umfang(array $export): array
    {
        $umfang = [];

        foreach ($export as $abschnitt => $inhalt) {
            if (is_array($inhalt) && array_is_list($inhalt)) {
                $umfang[$abschnitt] = count($inhalt);
            }
        }

        return $umfang;
    }

    /**
     * @param  array<string, mixed>|null  $ergebnis
     */
    private function protokolliere(
        Contact $kontakt,
        DataSubjectRequestType $art,
        ?User $wer,
        ?array $ergebnis,
    ): DataSubjectRequest {
        $vorgang = new DataSubjectRequest;
        $vorgang->contact_id = $kontakt->getKey();
        $vorgang->requested_by_user_id = $wer?->getKey();
        $vorgang->type = $art;
        $vorgang->status = DataSubjectRequestStatus::Completed;
        $vorgang->result = $ergebnis;
        $vorgang->completed_at = CarbonImmutable::now();
        $vorgang->save();

        return $vorgang;
    }
}
