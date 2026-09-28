<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Models\Attachment;
use App\Models\Branding;
use App\Models\Organization;
use App\Models\PlatformMailSetting;
use App\Support\Markenstil;

/**
 * Kopf, Fuss und Farbe einer Mail -- **die Praxis bei Mails an Patientinnen,
 * der Betreiber bei Mails an Konten** (WP-07, WP-36, WP-37).
 *
 * Bis zum 26.09.2026 trug das Mailgeruest `config('app.name')`: der
 * Absendername war die Praxis, Kopf und Fuss waren "Mrs. Beauty". Eine
 * Patientin, die eine Terminbestaetigung bekommt, soll nicht rätseln, wer
 * ihr schreibt.
 *
 * **Gebaut im Mandantenkontext, nicht in der Mail.** Der Versand laeuft in
 * einer Warteschlange; was die Mail braucht, steht fest, bevor sie entsteht.
 * Nur Zeichenketten -- kein Modell in der Nutzlast (B21).
 */
final class Mailmarke
{
    /** Nur so kommt eine Farbe in das Theme -- nie als freier Text (WP-36 AK 16). */
    private const HEX = '/^#[0-9A-F]{6}$/';

    public readonly ?string $farbe;

    /**
     * @param  list<string>  $signatur  Zeilen, roh -- maskiert wird beim Ausgeben
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $logo = null,
        public readonly ?string $impressum = null,
        public readonly ?string $datenschutz = null,
        public readonly ?string $startseite = null,
        ?string $farbe = null,
        public readonly array $signatur = [],
        public readonly ?string $fussnote = null,
    ) {
        $farbe = is_string($farbe) ? strtoupper(trim($farbe)) : null;

        $this->farbe = is_string($farbe) && preg_match(self::HEX, $farbe) === 1 ? $farbe : null;
    }

    /** Fuer die geltende Praxis -- mit dem, was sie im Erscheinungsbild hinterlegt hat. */
    public static function fuer(Organization $praxis): self
    {
        $bild = Branding::query()->first();
        $logo = $bild?->logo();

        return new self(
            name: $praxis->name,
            logo: $logo instanceof Attachment ? route('buchung.logo', ['praxis' => $praxis->slug]) : null,
            impressum: self::adresse($bild?->imprint_url),
            datenschutz: self::adresse($bild?->privacy_url),
            startseite: route('buchung.zeigen', ['praxis' => $praxis->slug]),
            farbe: Markenstil::hexFuer($bild?->primary_color),
            signatur: self::zeilen($bild?->mail_signature),
        );
    }

    /** Fuer die Produktmails -- mit dem, was der Betreiber hinterlegt hat (WP-37). */
    public static function fuerPlattform(?PlatformMailSetting $einstellung = null): self
    {
        $einstellung ??= PlatformMailSetting::aktuell();

        $logo = is_string($einstellung->logo_path) && $einstellung->logo_path !== ''
            ? route('mail.logo', ['fassung' => $einstellung->logo_version])
            : null;

        $fussnote = trim((string) $einstellung->footer_text);

        return new self(
            name: (string) config('app.name'),
            logo: $logo,
            impressum: self::adresse($einstellung->imprint_url),
            datenschutz: self::adresse($einstellung->privacy_url),
            startseite: (string) config('app.url'),
            farbe: Markenstil::hexFuer($einstellung->accent_color),
            fussnote: $fussnote === '' ? null : $fussnote,
        );
    }

    /** Die Farbe, die das Theme setzt -- die der Marke oder die des Produkts. */
    public function akzent(): string
    {
        return $this->farbe ?? (string) config('mrs.mail.produktfarbe');
    }

    private static function adresse(?string $wert): ?string
    {
        return is_string($wert) && str_starts_with($wert, 'https://') ? $wert : null;
    }

    /**
     * @return list<string>
     */
    private static function zeilen(?string $text): array
    {
        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', trim($text)) ?: []),
            fn (string $zeile): bool => $zeile !== '',
        ));
    }
}
