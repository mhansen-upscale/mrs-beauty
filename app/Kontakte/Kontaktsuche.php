<?php

declare(strict_types=1);

namespace App\Kontakte;

use App\Models\Contact;
use App\Support\Telefonnummer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Kontakte finden, ohne sie entschluesselt durchsuchen zu koennen.
 *
 * **Nur exakt.** Ein verschluesseltes Feld kennt kein LIKE: jeder
 * Schreibvorgang zieht einen eigenen Nonce, derselbe Klartext ergibt zweimal
 * verschiedene Chiffrate. Der blinde Index (Entscheidung A6) macht den
 * Gleichheitsvergleich wieder moeglich, mehr nicht -- "Mül" findet nichts,
 * "Müller" findet alle Müllers.
 *
 * Das ist Entscheidung P8 und kein Versaeumnis. Es gehoert in die Oberflaeche
 * als Hinweis, sonst haelt der Empfang die Suche fuer kaputt.
 */
final class Kontaktsuche
{
    /**
     * @return EloquentCollection<int, Contact>
     */
    public function suche(string $begriff, int $hoechstens = 20): EloquentCollection
    {
        $begriff = trim($begriff);

        if ($begriff === '') {
            return new EloquentCollection;
        }

        $feld = self::feldFuer($begriff);

        /** @var EloquentCollection<int, Contact> */
        return Contact::query()
            ->whereBlind($feld, $begriff)
            ->orderByDesc('created_at')
            ->limit($hoechstens)
            ->get();
    }

    /**
     * Welches Feld zu diesem Suchbegriff gehoert.
     *
     * Eine Entscheidung am Zeichen, nicht am Datensatz: ein @ ist eine
     * Adresse, etwas mit Ziffern und Plus eine Nummer, alles andere ein Name.
     */
    public static function feldFuer(string $begriff): string
    {
        if (str_contains($begriff, '@')) {
            return 'email';
        }

        return Telefonnummer::istGueltig($begriff) ? 'phone' : 'last_name';
    }

    /**
     * Findet einen Kontakt ueber ein hartes Signal oder legt ihn an.
     *
     * Entscheidung D6 laesst das automatische Zusammenfuehren nur bei
     * identischer E-Mail **oder Telefonnummer** zu. Dieselbe Regel gilt beim
     * Anlegen, damit derselbe Mensch nicht bei jedem Termin erneut entsteht.
     *
     * Die Nummer traegt seit WP-16 einen blinden Index ueber der E.164-Form --
     * "0170 1234567" findet damit den Datensatz, der als "+49 170 1234567"
     * angelegt wurde.
     *
     * @param  array{first_name: string, last_name: string, email?: string|null, phone?: string|null}  $daten
     */
    public function findeOderLege(array $daten): Contact
    {
        $vorhanden = $this->findeUeberHartesSignal(
            $daten['email'] ?? null,
            $daten['phone'] ?? null,
        );

        return $vorhanden ?? Contact::create($daten);
    }

    /**
     * Der Kontakt zu einer E-Mail oder Telefonnummer -- exakt, sonst nichts.
     *
     * Das ist die Grenze aus Entscheidung D6: zwei Datensaetze derselben
     * Person sind reparierbar, zwei Personen in einem Datensatz nicht.
     */
    public function findeUeberHartesSignal(?string $email, ?string $telefon, ?Contact $ausser = null): ?Contact
    {
        foreach ([['email', $email], ['phone', $telefon]] as [$feld, $wert]) {
            if (! is_string($wert) || $wert === '') {
                continue;
            }

            $treffer = Contact::query()
                ->whereBlind($feld, $wert)
                ->when($ausser instanceof Contact, fn ($abfrage) => $abfrage->whereKeyNot($ausser?->getKey()))
                ->first();

            if ($treffer instanceof Contact) {
                return $treffer;
            }
        }

        return null;
    }
}
