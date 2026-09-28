<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Benachrichtigung\Vorlagen\Betreffpruefung;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Benachrichtigung\Vorlagen\Textpruefung;
use App\Enums\Mailart;
use App\Enums\Mailfeld;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Eine Vorlage, wie sie gespeichert oder in der Vorschau gezeigt wird (C17).
 *
 * **Abgelehnt wird am Feld** -- dieselbe Pruefung fuer Speichern, Vorschau und
 * Probemail. Die Berechtigung pruefen Route (`can:` bzw. `betreiber:`) und
 * Controller; diese Klasse prueft den Text.
 */
final class MailvorlageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $regeln = [];

        foreach (Mailfeld::cases() as $feld) {
            $regeln[$feld->value] = $feld === Mailfeld::Betreff
                ? ['required', 'string']
                : ['nullable', 'string'];
        }

        return $regeln;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['subject.required' => 'Der Betreff darf nicht leer sein.'];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $art = $this->mailart();
            $text = $this->text();

            foreach ((new Textpruefung)->pruefeAlle($art, $text) as $spalte => $meldungen) {
                foreach ($meldungen as $meldung) {
                    $validator->errors()->add($spalte, $meldung);
                }
            }

            // **Der Betreff nennt keine Behandlung** -- gegen den Katalog der
            // Praxis, die gerade speichert (C17).
            if ($art->istTerminmail() && ($treffer = (new Betreffpruefung)->treffer($text->betreff)) !== null) {
                $validator->errors()->add(
                    Mailfeld::Betreff->value,
                    "Der Betreff nennt „{$treffer}“. Er erscheint auf dem Sperrbildschirm — bitte ohne Behandlung.",
                );
            }
        }];
    }

    public function mailart(): Mailart
    {
        $art = $this->route('mailart');

        return $art instanceof Mailart ? $art : Mailart::from((string) $art);
    }

    public function text(): Mailtext
    {
        return Mailtext::aus($this->only(array_map(fn (Mailfeld $feld): string => $feld->value, Mailfeld::cases())));
    }
}
