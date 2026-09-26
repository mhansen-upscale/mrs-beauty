<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Models\Attachment;
use App\Models\Branding;
use App\Models\Organization;

/**
 * Kopf und Fuss einer Mail an Patientinnen -- **die Praxis, nicht wir**
 * (offen seit WP-13, gehoert zum Whitelabel aus WP-07).
 *
 * Bis zum 26.09.2026 trug das Mailgeruest `config('app.name')`: der
 * Absendername war die Praxis, Kopf und Fuss waren "Mrs. Beauty". Eine
 * Patientin, die eine Terminbestaetigung bekommt, soll nicht rätseln, wer
 * ihr schreibt.
 *
 * **Gebaut im Mandantenkontext, nicht in der Mail.** Der Versand laeuft in
 * einer Warteschlange; was die Mail braucht, steht fest, bevor sie entsteht.
 */
final class Mailmarke
{
    public function __construct(
        public readonly string $praxisname,
        public readonly ?string $logo = null,
        public readonly ?string $impressum = null,
        public readonly ?string $datenschutz = null,
        public readonly ?string $buchungsseite = null,
    ) {}

    /** Fuer die geltende Praxis -- mit dem, was sie im Erscheinungsbild hinterlegt hat. */
    public static function fuer(Organization $praxis): self
    {
        $bild = Branding::query()->first();
        $logo = $bild?->logo();

        return new self(
            praxisname: $praxis->name,
            logo: $logo instanceof Attachment ? route('buchung.logo', ['praxis' => $praxis->slug]) : null,
            impressum: self::adresse($bild?->imprint_url),
            datenschutz: self::adresse($bild?->privacy_url),
            buchungsseite: route('buchung.zeigen', ['praxis' => $praxis->slug]),
        );
    }

    private static function adresse(?string $wert): ?string
    {
        return is_string($wert) && str_starts_with($wert, 'https://') ? $wert : null;
    }
}
