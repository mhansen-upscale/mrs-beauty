<?php

declare(strict_types=1);

namespace App\Kontakte;

use App\Models\Appointment;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\User;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Zwei Datensaetze, ein Mensch -- und der Weg zurueck (Entscheidungen D6, D7).
 *
 * **Die Richtung der Asymmetrie ist der ganze Punkt.** Zwei Datensaetze
 * derselben Person sind aergerlich und reparierbar. Zwei Personen in einem
 * Datensatz sind ein Datenschutzvorfall -- in einer aesthetischen Praxis
 * heisst das, dass jemand die Termine eines anderen sieht. Automatisch
 * zusammengefuehrt wird deshalb nur bei hartem Signal; alles andere ist ein
 * Vorschlag, den ein Mensch annimmt.
 */
final class Zusammenfuehrung
{
    /**
     * Fuehrt den Verlierer in den Gewinner.
     *
     * Termine und Kanalidentitaeten wechseln, leere Felder des Gewinners
     * werden ergaenzt, der Verlierer wird geloescht -- echt, ohne Soft Delete
     * (Entscheidung A12). Was dabei verloren ginge, steht vorher im Snapshot.
     */
    public function fuehreZusammen(Contact $gewinner, Contact $verlierer, ?User $wer = null): ContactMerge
    {
        if ($gewinner->getKey() === $verlierer->getKey()) {
            throw Nichtzusammenfuehrbar::derselbeKontakt();
        }

        // Der globale Scope schliesst das bereits aus. Die Pruefung steht
        // trotzdem hier: ein Merge ueber Mandantengrenzen waere kein
        // Schoenheitsfehler, sondern Regel 1 gebrochen (Entscheidung D10).
        if ($gewinner->organization_id !== $verlierer->organization_id) {
            throw Nichtzusammenfuehrbar::fremderMandant();
        }

        return DB::transaction(function () use ($gewinner, $verlierer, $wer): ContactMerge {
            $termine = Appointment::query()
                ->where('contact_id', $verlierer->getKey())
                ->pluck('id')
                ->map(fn (mixed $id): string => Uuid::toString((string) $id))
                ->all();

            $identitaeten = ChannelIdentity::query()
                ->where('contact_id', $verlierer->getKey())
                ->pluck('id')
                ->map(fn (mixed $id): string => Uuid::toString((string) $id))
                ->all();

            $ergaenzt = $this->ergaenze($gewinner, $verlierer);

            $vorgang = new ContactMerge;
            $vorgang->winner_contact_id = $gewinner->getKey();
            $vorgang->loser_contact_id = $verlierer->getKey();
            $vorgang->merged_by_user_id = $wer?->getKey();
            $vorgang->snapshot = (string) json_encode([
                'kontakt' => [
                    'uuid' => (string) $verlierer->uuid,
                    'first_name' => $verlierer->first_name,
                    'last_name' => $verlierer->last_name,
                    'email' => $verlierer->email,
                    'phone' => $verlierer->phone,
                    'created_at' => $verlierer->created_at?->toIso8601String(),
                ],
                'termine' => $termine,
                'identitaeten' => $identitaeten,
                'ergaenzt' => $ergaenzt,
            ]);
            $vorgang->snapshot_expires_at = CarbonImmutable::now()
                ->addDays((int) config('mrs.contacts.merge_snapshot_days', 30));
            $vorgang->save();

            Appointment::query()
                ->where('contact_id', $verlierer->getKey())
                ->update(['contact_id' => $gewinner->getKey()]);

            ChannelIdentity::query()
                ->where('contact_id', $verlierer->getKey())
                ->update(['contact_id' => $gewinner->getKey()]);

            $verlierer->delete();

            return $vorgang;
        });
    }

    /**
     * Der Weg zurueck.
     *
     * Der Kontakt entsteht mit **seiner alten Kennung** neu -- nur so finden
     * die Termine und Identitaeten zurueck, die im Snapshot stehen.
     */
    public function macheRueckgaengig(ContactMerge $vorgang, ?CarbonImmutable $jetzt = null): Contact
    {
        $jetzt ??= CarbonImmutable::now();

        if (! $vorgang->istUmkehrbar($jetzt)) {
            throw Nichtzusammenfuehrbar::nichtMehrUmkehrbar();
        }

        $inhalt = $vorgang->inhalt();

        return DB::transaction(function () use ($vorgang, $inhalt, $jetzt): Contact {
            /** @var array<string, mixed> $daten */
            $daten = is_array($inhalt['kontakt'] ?? null) ? $inhalt['kontakt'] : [];

            $verlierer = new Contact;
            $verlierer->setAttribute('id', $vorgang->loser_contact_id);
            $verlierer->first_name = (string) ($daten['first_name'] ?? '');
            $verlierer->last_name = (string) ($daten['last_name'] ?? '');
            $verlierer->email = is_string($daten['email'] ?? null) ? $daten['email'] : null;
            $verlierer->phone = is_string($daten['phone'] ?? null) ? $daten['phone'] : null;
            $verlierer->save();

            Appointment::query()
                ->whereUuid($this->kennungen($inhalt, 'termine'))
                ->update(['contact_id' => $verlierer->getKey()]);

            ChannelIdentity::query()
                ->whereUuid($this->kennungen($inhalt, 'identitaeten'))
                ->update(['contact_id' => $verlierer->getKey()]);

            // Was beim Zusammenfuehren am Gewinner ergaenzt wurde, gehoerte
            // ihm nie.
            $gewinner = $vorgang->winner;

            foreach ($this->kennungen($inhalt, 'ergaenzt') as $feld) {
                $gewinner->setAttribute($feld, null);
            }

            $gewinner->save();

            $vorgang->reverted_at = $jetzt;

            // Der Snapshot hat seinen Zweck erfuellt und ist Personendaten --
            // er bleibt nicht liegen.
            $vorgang->snapshot = null;
            $vorgang->save();

            return $verlierer;
        });
    }

    /**
     * Kontakte, die dieselbe Person sein koennten -- ohne hartes Signal.
     *
     * Ohne entschluesselte Felder gibt es kein Levenshtein (Entscheidung P8).
     * Ein Vorschlag entsteht deshalb aus exakter Uebereinstimmung auf
     * Feldern, die **fuer sich allein nicht genuegen**: gleicher Nachname und
     * gleicher Vorname.
     *
     * Wer eine E-Mail oder Nummer gemeinsam hat, taucht hier nicht auf -- der
     * Fall ist schon beim Anlegen entschieden.
     *
     * @return array<int, array{0: Contact, 1: Contact}>
     */
    public function vorschlaege(int $hoechstens = 20): array
    {
        $mehrfach = Contact::query()
            ->selectRaw('last_name_bidx, count(*) as anzahl')
            ->whereNotNull('last_name_bidx')
            ->groupBy('last_name_bidx')
            ->havingRaw('count(*) > 1')
            ->pluck('last_name_bidx');

        if ($mehrfach->isEmpty()) {
            return [];
        }

        $gruppen = Contact::query()
            ->whereIn('last_name_bidx', $mehrfach->all())
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Contact $kontakt): string => mb_strtolower(trim($kontakt->name())));

        $paare = [];

        foreach ($gruppen as $gruppe) {
            /** @var list<Contact> $kontakte */
            $kontakte = $gruppe->values()->all();
            $anzahl = count($kontakte);

            for ($i = 0; $i < $anzahl - 1; $i++) {
                for ($j = $i + 1; $j < $anzahl; $j++) {
                    if (count($paare) >= $hoechstens) {
                        return $paare;
                    }

                    $paare[] = [$kontakte[$i], $kontakte[$j]];
                }
            }
        }

        return $paare;
    }

    /**
     * Fuellt leere Felder des Gewinners aus dem Verlierer.
     *
     * @return list<string>
     */
    private function ergaenze(Contact $gewinner, Contact $verlierer): array
    {
        $ergaenzt = [];

        foreach (['email', 'phone'] as $feld) {
            $vorhanden = $gewinner->getAttribute($feld);
            $anderer = $verlierer->getAttribute($feld);

            if ((! is_string($vorhanden) || $vorhanden === '') && is_string($anderer) && $anderer !== '') {
                $gewinner->setAttribute($feld, $anderer);
                $ergaenzt[] = $feld;
            }
        }

        if ($ergaenzt !== []) {
            $gewinner->save();
        }

        return $ergaenzt;
    }

    /**
     * @param  array<string, mixed>  $inhalt
     * @return list<string>
     */
    private function kennungen(array $inhalt, string $schluessel): array
    {
        $werte = $inhalt[$schluessel] ?? [];

        if (! is_array($werte)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $wert): string => is_string($wert) ? $wert : '', $werte),
            fn (string $wert): bool => $wert !== '',
        ));
    }
}
