<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\ChannelIdentity;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Taggable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Der Auskunftsexport nach Artikel 15 DSGVO.
 *
 * **In lesbarer Form**, und das ist die eigentliche Anforderung: eine
 * Datenbankkopie erfuellt sie nicht. Wer Auskunft verlangt, soll verstehen,
 * was ueber ihn gespeichert ist -- Kennungen, Rohbytes und Statuswerte ohne
 * Beschriftung leisten das nicht.
 *
 * Alles an einer Stelle, weil der Fehler sonst vorprogrammiert ist: ueber
 * zwoelf Tabellen von Hand zusammenzusuchen heisst, eine zu vergessen.
 */
final class Auskunft
{
    /**
     * @return array<string, mixed>
     */
    public function fuerKontakt(Contact $kontakt): array
    {
        $termine = Appointment::query()
            ->where('contact_id', $kontakt->getKey())
            ->with(['appointmentType', 'practitioner', 'location'])
            ->orderBy('starts_at')
            ->get();

        $anfragen = Lead::query()
            ->where('contact_id', $kontakt->getKey())
            ->with('treatment')
            ->orderBy('created_at')
            ->get();

        $identitaeten = ChannelIdentity::query()
            ->where('contact_id', $kontakt->getKey())
            ->get();

        return [
            'erstellt_am' => now()->toIso8601String(),

            'person' => [
                'vorname' => $kontakt->first_name,
                'nachname' => $kontakt->last_name,
                'e_mail' => $kontakt->email,
                'telefon' => $kontakt->telefonAnzeige(),
                'angelegt_am' => $kontakt->created_at?->toIso8601String(),
            ],

            'kanaele' => $identitaeten
                ->map(fn (ChannelIdentity $identitaet): array => [
                    'kanal' => $identitaet->channel->label(),
                    'kennung' => $identitaet->kennungAnzeige(),
                    'anzeigename' => $identitaet->display_name,
                    'zuletzt_gesehen' => $identitaet->last_seen_at?->toIso8601String(),
                ])
                ->all(),

            'einwilligungen' => Consent::query()
                ->whereIn('channel_identity_id', $identitaeten->modelKeys())
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (Consent $eintrag): array => [
                    'zweck' => $eintrag->type->label(),
                    'vorgang' => $eintrag->action->label(),
                    'zeitpunkt' => $eintrag->occurred_at->toIso8601String(),
                    'textstand' => $eintrag->text_version,
                    // Der Text selbst gehoert dazu: ohne ihn ist nicht
                    // ersichtlich, wozu zugestimmt wurde.
                    'text' => $eintrag->text_snapshot,
                ])
                ->all(),

            'anfragen' => $anfragen
                ->map(fn (Lead $lead): array => [
                    'behandlung' => $lead->treatment?->name,
                    'stand' => $lead->status->label(),
                    'herkunft' => $lead->source->label(),
                    'angelegt_am' => $lead->created_at?->toIso8601String(),
                    'abgeschlossen_am' => $lead->closed_at?->toIso8601String(),
                ])
                ->all(),

            'termine' => $termine
                ->map(fn (Appointment $termin): array => [
                    'beginn' => $termin->location->ortszeit($termin->starts_at)->toIso8601String(),
                    'ende' => $termin->location->ortszeit($termin->ends_at)->toIso8601String(),
                    'terminart' => $termin->appointmentType->name,
                    'behandler' => $termin->practitioner->name(),
                    'standort' => $termin->location->name,
                    'stand' => $termin->status->label(),
                ])
                ->all(),

            'notizen' => $this->notizen($kontakt, $termine->modelKeys(), $anfragen->modelKeys())
                ->map(fn (Note $notiz): array => [
                    'text' => $notiz->body,
                    'verfasst_am' => $notiz->created_at?->toIso8601String(),
                ])
                ->all(),

            'schlagworte' => $this->schlagworte($kontakt, $termine->modelKeys(), $anfragen->modelKeys())
                ->load('tag')
                ->map(fn (Taggable $zuordnung): string => $zuordnung->tag->name)
                ->values()
                ->all(),

            // Die Dateien selbst werden nicht eingebettet -- sie gehen als
            // Anhang mit. Hier steht, welche es gibt, damit niemand
            // nachzaehlen muss.
            'anhaenge' => $this->anhaenge($kontakt, $termine->modelKeys(), $anfragen->modelKeys())
                ->map(fn (Attachment $anhang): array => [
                    'dateiname' => $anhang->original_name,
                    'art' => $anhang->context->label(),
                    'groesse_bytes' => $anhang->size_bytes,
                    'hochgeladen_am' => $anhang->created_at?->toIso8601String(),
                    'laeuft_ab_am' => $anhang->expires_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, mixed>  $termine
     * @param  array<int, mixed>  $anfragen
     * @return Collection<int, Note>
     */
    private function notizen(Contact $kontakt, array $termine, array $anfragen): Collection
    {
        return Note::query()
            ->where($this->bezug('notable', $kontakt, $termine, $anfragen))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  array<int, mixed>  $termine
     * @param  array<int, mixed>  $anfragen
     * @return Collection<int, Taggable>
     */
    private function schlagworte(Contact $kontakt, array $termine, array $anfragen): Collection
    {
        return Taggable::query()
            ->where($this->bezug('taggable', $kontakt, $termine, $anfragen))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  array<int, mixed>  $termine
     * @param  array<int, mixed>  $anfragen
     * @return Collection<int, Attachment>
     */
    private function anhaenge(Contact $kontakt, array $termine, array $anfragen): Collection
    {
        return Attachment::query()
            ->where($this->bezug('attachable', $kontakt, $termine, $anfragen))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Der Filter "haengt an dieser Person" -- einmal formuliert.
     *
     * Notizen, Schlagworte und Anhaenge haengen polymorph am Kontakt, an
     * seinen Terminen oder an seinen Anfragen. Dreimal dieselbe Bedingung zu
     * schreiben heisst, sie zweimal falsch zu schreiben.
     *
     * @param  array<int, mixed>  $termine
     * @param  array<int, mixed>  $anfragen
     */
    private function bezug(string $feld, Contact $kontakt, array $termine, array $anfragen): Closure
    {
        return function (Builder $gruppe) use ($feld, $kontakt, $termine, $anfragen): void {
            $gruppe->where(function (Builder $teil) use ($feld, $kontakt): void {
                $teil->where($feld.'_type', Contact::class)->where($feld.'_id', $kontakt->getKey());
            });

            if ($termine !== []) {
                $gruppe->orWhere(function (Builder $teil) use ($feld, $termine): void {
                    $teil->where($feld.'_type', Appointment::class)->whereIn($feld.'_id', $termine);
                });
            }

            if ($anfragen !== []) {
                $gruppe->orWhere(function (Builder $teil) use ($feld, $anfragen): void {
                    $teil->where($feld.'_type', Lead::class)->whereIn($feld.'_id', $anfragen);
                });
            }
        };
    }
}
