<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Attachment;
use App\Models\ChannelIdentity;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Note;
use App\Models\Taggable;
use Illuminate\Support\Facades\DB;

/**
 * Loescht alles, was zu einer Person gehoert -- ueber alle Tabellen.
 *
 * Artikel 17 DSGVO, und Entscheidung A12 macht es ernst: **kein Soft
 * Delete.** Eine Loeschanfrage muss echt loeschen, sonst ist sie keine.
 *
 * Die Reihenfolge ist nicht kosmetisch. Slots verweisen auf Termine, ohne
 * kaskadierend zu loeschen -- wer den Termin zuerst entfernt, laeuft in einen
 * Fremdschluesselfehler. Und Dateien liegen ausserhalb der Datenbank: eine
 * Zeile zu loeschen, ohne die Datei mitzunehmen, laesst das Foto liegen,
 * waehrend der Datensatz weg ist.
 *
 * **Was bleibt: das Protokoll.** `audit_logs` fuehrt Feldnamen, keine Werte
 * (Entscheidung C5) -- es ist der Nachweis, dass verarbeitet und geloescht
 * wurde, und enthaelt selbst keine Personendaten. Seine eigene Frist steht in
 * der Aufbewahrung.
 */
final class Loeschung
{
    public function __construct(private readonly Anhangspeicher $anhaenge) {}

    /**
     * @return array<string, int> Was entfernt wurde, je Tabelle
     */
    public function fuerKontakt(Contact $kontakt): array
    {
        return DB::transaction(function () use ($kontakt): array {
            $termine = Appointment::query()->where('contact_id', $kontakt->getKey())->pluck('id')->all();
            $anfragen = Lead::query()->where('contact_id', $kontakt->getKey())->pluck('id')->all();
            $identitaeten = ChannelIdentity::query()->where('contact_id', $kontakt->getKey())->pluck('id')->all();

            $traeger = [
                Contact::class => [$kontakt->getKey()],
                Appointment::class => $termine,
                Lead::class => $anfragen,
            ];

            $gezaehlt = [
                'attachments' => $this->anhaengeWeg($traeger),
                'notes' => $this->polymorphWeg(Note::class, 'notable', $traeger),
                'taggables' => $this->polymorphWeg(Taggable::class, 'taggable', $traeger),
                'consents' => Consent::query()->whereIn('channel_identity_id', $identitaeten)->count(),
                'conversations' => Conversation::query()->whereIn('channel_identity_id', $identitaeten)->count(),
                'messages' => Message::query()
                    ->whereIn('conversation_id', Conversation::query()
                        ->whereIn('channel_identity_id', $identitaeten)
                        ->pluck('id'))
                    ->count(),
                'channel_identities' => count($identitaeten),
                'leads' => count($anfragen),
                'appointments' => count($termine),
                'contact_merges' => $this->sicherungsstaendeWeg($kontakt),
            ];

            // Erst die Zeit freigeben: die Slots verweisen auf den Termin,
            // ohne kaskadierend zu loeschen.
            AppointmentSlot::query()
                ->whereIn('appointment_id', $termine)
                ->update(['appointment_id' => null]);

            // Loescht Einwilligungen, Konversationen und deren Nachrichten
            // mit (Kaskade).
            ChannelIdentity::query()->whereIn('id', $identitaeten)->delete();

            Lead::query()->whereIn('id', $anfragen)->delete();

            // Loescht Terminnachrichten und Kalenderverknuepfungen mit.
            Appointment::query()->whereIn('id', $termine)->delete();

            $kontakt->delete();

            $gezaehlt['contacts'] = 1;

            return $gezaehlt;
        });
    }

    /**
     * Anhaenge an allen Traegern -- Datei und Datensatz.
     *
     * @param  array<class-string, array<int, mixed>>  $traeger
     */
    private function anhaengeWeg(array $traeger): int
    {
        $anzahl = 0;

        foreach ($traeger as $typ => $schluessel) {
            if ($schluessel === []) {
                continue;
            }

            $anhaenge = Attachment::query()
                ->where('attachable_type', $typ)
                ->whereIn('attachable_id', $schluessel)
                ->get();

            foreach ($anhaenge as $anhang) {
                $this->anhaenge->entferne($anhang);
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * @param  class-string<Note|Taggable>  $modell
     * @param  array<class-string, array<int, mixed>>  $traeger
     */
    private function polymorphWeg(string $modell, string $bezug, array $traeger): int
    {
        $anzahl = 0;

        foreach ($traeger as $typ => $schluessel) {
            if ($schluessel === []) {
                continue;
            }

            $anzahl += $modell::query()
                ->where($bezug.'_type', $typ)
                ->whereIn($bezug.'_id', $schluessel)
                ->delete();
        }

        return $anzahl;
    }

    /**
     * Sicherungsstaende, in denen diese Person vorkommt.
     *
     * Der Snapshot einer Zusammenfuehrung enthaelt die Felder des Verlierers
     * (Entscheidung D7). Wer den Kontakt loescht und den Snapshot stehen
     * laesst, hat ihn nicht geloescht -- nur versteckt. Der Vorgang selbst
     * bleibt als Spur, ohne Inhalt.
     */
    private function sicherungsstaendeWeg(Contact $kontakt): int
    {
        $anzahl = 0;

        $vorgaenge = ContactMerge::query()
            ->whereNotNull('snapshot')
            ->where(function ($gruppe) use ($kontakt): void {
                $gruppe->where('loser_contact_id', $kontakt->getKey())
                    ->orWhere('winner_contact_id', $kontakt->getKey());
            })
            ->get();

        foreach ($vorgaenge as $vorgang) {
            $vorgang->snapshot = null;
            $vorgang->save();
            $anzahl++;
        }

        return $anzahl;
    }
}
