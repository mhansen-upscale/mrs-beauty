<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\ZweiFaktorVerfahren;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Ein-, um- und abschalten, zuruecksetzen -- jeweils mit Protokoll (WP-35).
 *
 * **Eine Stelle fuer Einstellungen, Teamseite, Betreiberverwaltung und
 * Konsole.** Alle vier sollen dasselbe tun und dasselbe protokollieren.
 *
 * **Gespeichert wird ohne das allgemeine Protokoll** (saveQuietly). Das
 * Ereignis hier sagt, was geschah; ein zweiter Eintrag "Datensatz geaendert:
 * zwei_faktor_geheimnis" daneben sagte nur, dass etwas geschah.
 *
 * **Einschalten und Zuruecksetzen erneuern das remember_token.** Sonst
 * umgeht ein "Angemeldet bleiben"-Cookie von vorgestern den neuen Faktor --
 * oder das Cookie eines verlorenen Telefons den zurueckgesetzten.
 */
final class ZweiterFaktor
{
    public function __construct(
        private readonly AuditLogger $protokoll,
        private readonly Wiederherstellungscodes $codes,
        private readonly EmailCode $emailCode,
    ) {}

    /**
     * @return list<string> Die Wiederherstellungscodes, einmal im Klartext
     */
    public function schalteAppEin(User $person, string $geheimnis, int $schritt): array
    {
        $vorher = $person->zweiFaktorVerfahren();

        $person->zwei_faktor_verfahren = ZweiFaktorVerfahren::Authenticator;
        $person->zwei_faktor_geheimnis = $geheimnis;
        $person->zwei_faktor_letzter_schritt = $schritt;
        $person->zwei_faktor_bestaetigt_at = CarbonImmutable::now();
        $person->setRememberToken(Str::random(60));

        // Speichert alles zusammen, ohne allgemeines Protokoll.
        $codes = $this->codes->erzeuge($person);

        $this->protokolliere(AuditEvent::TwoFactorEnabled, $person, $this->wechsel(ZweiFaktorVerfahren::Authenticator, $vorher));

        return $codes;
    }

    public function schalteEmailEin(User $person): void
    {
        $vorher = $person->zweiFaktorVerfahren();

        $this->leere($person);
        $person->zwei_faktor_verfahren = ZweiFaktorVerfahren::Email;
        $person->zwei_faktor_bestaetigt_at = CarbonImmutable::now();
        $person->setRememberToken(Str::random(60));
        $person->saveQuietly();

        $this->protokolliere(AuditEvent::TwoFactorEnabled, $person, $this->wechsel(ZweiFaktorVerfahren::Email, $vorher));
    }

    public function schalteAus(User $person): void
    {
        $vorher = $person->zweiFaktorVerfahren();

        $this->leere($person);
        $person->saveQuietly();

        $this->protokolliere(AuditEvent::TwoFactorDisabled, $person, ['verfahren' => $vorher?->value]);
    }

    /**
     * Fuer jemanden, der nicht mehr hineinkommt. Handelnde ist, wer
     * angemeldet ist -- auf der Konsole das System, mit Begruendung.
     */
    public function setzeZurueck(User $person, ?string $begruendung = null): void
    {
        $vorher = $person->zweiFaktorVerfahren();

        $this->leere($person);
        $person->setRememberToken(Str::random(60));
        $person->saveQuietly();

        $this->protokolliere(AuditEvent::TwoFactorReset, $person, ['verfahren' => $vorher?->value], $begruendung);
    }

    /**
     * @return list<string> Die neuen Codes, einmal im Klartext
     */
    public function erneuereCodes(User $person): array
    {
        $codes = $this->codes->erzeuge($person);

        $this->protokolliere(AuditEvent::TwoFactorRecoveryCodesRenewed, $person, ['anzahl' => count($codes)]);

        return $codes;
    }

    public function wiederherstellungscodeEingeloest(User $person): void
    {
        $this->protokolliere(AuditEvent::TwoFactorRecoveryCodeUsed, $person, ['uebrig' => $this->codes->uebrig($person)]);
    }

    public function anmeldungVerworfen(User $person): void
    {
        $this->protokolliere(AuditEvent::TwoFactorChallengeLocked, $person);
    }

    private function leere(User $person): void
    {
        $person->zwei_faktor_verfahren = null;
        $person->zwei_faktor_geheimnis = null;
        $person->zwei_faktor_wiederherstellung = null;
        $person->zwei_faktor_bestaetigt_at = null;
        $person->zwei_faktor_letzter_schritt = null;

        // Ein Code, der schon unterwegs ist, gilt fuer das alte Verfahren.
        $this->emailCode->verwerfe($person, Zweck::Anmeldung);
    }

    /**
     * @return array<string, string>
     */
    private function wechsel(ZweiFaktorVerfahren $neu, ?ZweiFaktorVerfahren $vorher): array
    {
        return $vorher instanceof ZweiFaktorVerfahren && $vorher !== $neu
            ? ['verfahren' => $neu->value, 'vorher' => $vorher->value]
            : ['verfahren' => $neu->value];
    }

    /**
     * **Die Organisation ausdruecklich.** Ohne sie nimmt das Protokoll den
     * geltenden Mandanten -- und der Eintrag eines Betreibers landete bei der
     * Praxis, die er gerade betreut.
     *
     * @param  array<string, mixed>  $kontext
     */
    private function protokolliere(AuditEvent $ereignis, User $person, array $kontext = [], ?string $begruendung = null): void
    {
        $organisation = $person->getAttributes()['organization_id'] ?? null;

        $this->protokoll->record(
            ereignis: $ereignis,
            gegenstand: $person,
            kontext: $kontext,
            begruendung: $begruendung,
            organizationId: is_string($organisation) ? $organisation : null,
            ohneOrganisation: ! is_string($organisation),
        );
    }
}
