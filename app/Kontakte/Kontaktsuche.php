<?php

declare(strict_types=1);

namespace App\Termine;

use App\Models\Contact;
use Illuminate\Support\Collection;

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
     * @return Collection<int, Contact>
     */
    public function suche(string $begriff, int $hoechstens = 20): Collection
    {
        $begriff = trim($begriff);

        if ($begriff === '') {
            return new Collection;
        }

        $feld = str_contains($begriff, '@') ? 'email' : 'last_name';

        /** @var Collection<int, Contact> */
        return Contact::query()
            ->whereBlind($feld, $begriff)
            ->orderByDesc('created_at')
            ->limit($hoechstens)
            ->get();
    }

    /**
     * Findet einen Kontakt ueber die exakte E-Mail-Adresse oder legt ihn an.
     *
     * Entscheidung D6 laesst das automatische Zusammenfuehren nur bei
     * identischer E-Mail oder Telefonnummer zu. Hier wird dieselbe Regel beim
     * Anlegen angewandt, damit derselbe Mensch nicht bei jedem Termin erneut
     * entsteht. Das richtige Zusammenfuehren -- mit Snapshot und umkehrbar --
     * gehoert zu WP-16.
     *
     * @param  array{first_name: string, last_name: string, email?: string|null, phone?: string|null}  $daten
     */
    public function findeOderLege(array $daten): Contact
    {
        $email = $daten['email'] ?? null;

        if (is_string($email) && $email !== '') {
            $vorhanden = Contact::query()->whereBlind('email', $email)->first();

            if ($vorhanden instanceof Contact) {
                return $vorhanden;
            }
        }

        return Contact::create($daten);
    }
}
