<?php

declare(strict_types=1);

namespace App\Agent\Guardrails;

use App\Agent\Klassifikation;
use App\Enums\GuardrailHit;
use App\Models\Attachment;
use App\Models\Message;

/**
 * Die harte Weiche (docs/fachlogik/agent.md, Schritt 4).
 *
 * **Sie laeuft vor jeder Textgenerierung und ist nicht ueberstimmbar.**
 *
 * Das Komplikationssignal ist bewusst eine **Wortstammsuche** und kein
 * Klassifikator: ein Klassifikator laesst sich ueberreden, eine Wortliste
 * nicht. Das ist der eine Fall, in dem eine starre Regel der besseren Technik
 * vorzuziehen ist -- und Falschausloesungen sind ausdruecklich erwuenscht.
 * "Habe ich danach Schmerzen?" ist eine medizinische Frage und gehoert
 * ohnehin zum Menschen.
 *
 * Gesucht wird in **Inhalt und Betreff**. Beides ist Text, den ein Fremder
 * geschrieben hat, und beides kann das Signal tragen.
 */
final class Weiche
{
    /**
     * @return list<GuardrailHit> Leer heisst: der Weg ist frei
     */
    public function pruefe(Message $nachricht, Klassifikation $einordnung): array
    {
        $treffer = [];

        // **Unabhaengig von der erkannten Absicht.** Ein Modell, das
        // "Meine Nase ist seit gestern stark geschwollen" fuer eine
        // Terminanfrage haelt, aendert daran nichts.
        if ($this->komplikation($nachricht)) {
            $treffer[] = GuardrailHit::Complication;
        }

        if ($this->bildAngehaengt($nachricht)) {
            // Der Agent bewertet keine Fotos. Niemals.
            $treffer[] = GuardrailHit::ImageAttachment;
        }

        $ausAbsicht = match (true) {
            $einordnung->absicht->value === 'medical_question' => GuardrailHit::MedicalQuestion,
            $einordnung->absicht->value === 'complaint' => GuardrailHit::Complaint,
            $einordnung->absicht->value === 'spam' => GuardrailHit::Spam,
            default => null,
        };

        if ($ausAbsicht instanceof GuardrailHit) {
            $treffer[] = $ausAbsicht;
        }

        return array_values(array_unique($treffer, SORT_REGULAR));
    }

    /**
     * Traegt die Nachricht ein Komplikationssignal?
     *
     * Die Liste steht in `config/mrs.php` (`agent.complication_stems`) mit
     * ihrer Fundstelle -- nicht im Code, damit eine Praxis sie ergaenzen kann,
     * ohne dass jemand eine Klasse anfasst.
     */
    public function komplikation(Message $nachricht): bool
    {
        $text = mb_strtolower(trim((string) $nachricht->subject.' '.(string) $nachricht->body));

        if ($text === '') {
            return false;
        }

        foreach ((array) config('mrs.agent.complication_stems', []) as $stamm) {
            if (is_string($stamm) && $stamm !== '' && str_contains($text, mb_strtolower($stamm))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Haengt ein Bild an?
     *
     * Geprueft wird der Medientyp der Nachricht **und** der abgelegten
     * Anhaenge: bei WhatsApp steht er an der Nachricht, bei einer Mail am
     * Anhang.
     */
    public function bildAngehaengt(Message $nachricht): bool
    {
        if (is_string($nachricht->media_type) && str_starts_with(mb_strtolower($nachricht->media_type), 'image')) {
            return true;
        }

        return $nachricht->attachments
            ->contains(fn (Attachment $anhang): bool => str_starts_with(mb_strtolower($anhang->mime), 'image'));
    }
}
