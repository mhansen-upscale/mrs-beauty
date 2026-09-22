<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Contracts\UsesBlindIndexes;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlindIndexes;
use App\Models\Concerns\MasksPersonalData;
use App\Support\Telefonnummer;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Person, die einen Termin hat oder haben will.
 *
 * **`contacts`, nicht `patients`** (Entscheidung D1). Der Name haelt die
 * Grenze im Code sichtbar: dieses Produkt fuehrt keine Patientenakte, sondern
 * einen Terminkalender. Wer die Tabelle `patients` nennt, hat den ersten
 * Schritt zu § 630f BGB schon getan.
 *
 * **WP-16 besitzt dieses Modell.** Hier steht nur, was ein Termin verlangt.
 * Kanalidentitaeten (D5), Einwilligungen (D8), Zusammenfuehrungen (D6, D7)
 * und Leads (D3) kommen dort.
 *
 * Alle vier Felder sind verschluesselt (Regel 3). Durchsuchbar sind davon nur
 * E-Mail und Nachname, ueber blinde Indizes und nur **exakt** -- das ist
 * Entscheidung P8, kein Versaeumnis.
 *
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Contact extends TenantModel implements HasPersonalData, UsesBlindIndexes
{
    use Auditable;
    use HasBlindIndexes;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use MasksPersonalData;

    protected $fillable = ['first_name', 'last_name', 'email', 'phone'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_name' => Encrypted::class,
            'last_name' => Encrypted::class,
            'email' => Encrypted::class,
            'phone' => Encrypted::class,
        ];
    }

    /**
     * Verschluesselt heisst personenbezogen -- sonst waere die
     * Verschluesselung sinnlos. Durchgesetzt von
     * tests/Feature/Audit/DeckungTest.php.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['first_name', 'last_name', 'email', 'phone'];
    }

    /**
     * @return array<string, string>
     */
    public function blindIndexes(): array
    {
        return [
            'email' => 'email_bidx',
            'last_name' => 'last_name_bidx',
            'phone' => 'phone_bidx',
        ];
    }

    /**
     * Die Telefonnummer wird vor dem Index nach E.164 gebracht (WP-16).
     *
     * Ohne das ergaeben "+49 170 1234567" und "01701234567" zwei verschiedene
     * Hashes -- und damit zwei Personen, die niemand zusammenfuehrt. Die
     * Suche traefe still nie: kein Fehler, keine Meldung, kein Ergebnis.
     *
     * Was sich nicht lesen laesst, bekommt keinen Index und wird trotzdem
     * gespeichert. Eine unleserliche Nummer ist ein Kontaktweg, den jemand
     * abtippen kann.
     */
    public function blindIndexValue(string $feld, mixed $wert): ?string
    {
        if (! is_string($wert) || $wert === '') {
            return null;
        }

        // Das Trait wuerde hier den Wert selbst liefern; fuer alle anderen
        // Felder genuegt das, weil BlindIndex::hash() kuerzt und kleinschreibt.
        return $feld === 'phone' ? Telefonnummer::e164($wert) : $wert;
    }

    /**
     * Nichts. Jedes Feld dieses Modells ist personenbezogen; ein Protokoll
     * mit Werten waere hier eine zweite, unverschluesselte Datenhaltung
     * (Entscheidung C5).
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return [];
    }

    public function name(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Die Nummer, wie man sie liest -- gespeichert bleibt, was getippt wurde. */
    public function telefonAnzeige(): ?string
    {
        return is_string($this->phone) && $this->phone !== ''
            ? Telefonnummer::anzeige($this->phone)
            : null;
    }

    /**
     * @return HasMany<ChannelIdentity, $this>
     */
    public function channelIdentities(): HasMany
    {
        return $this->hasMany(ChannelIdentity::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
