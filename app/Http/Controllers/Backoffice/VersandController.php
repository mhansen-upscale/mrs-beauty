<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Jobs\PlattformversandPruefen;
use App\Models\PlatformMailSetting;
use App\Models\User;
use App\Support\Markenstil;
use App\Whitelabel\Farbpruefung;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Versand der Plattform (WP-37, B23): Server, Absender und Aussehen der
 * Produktmails.
 *
 * **Gespeichert ist nicht geprueft.** Wer Server oder Zugangsdaten aendert,
 * macht ihn wieder ungeprueft -- bis die Probemail ueber genau diese Fassung
 * ging, gilt `.env`. Wer sich beim Hostnamen vertippt, sperrt so niemanden
 * aus, der auf einen Anmeldecode wartet.
 *
 * **Das Passwort geht nie zurueck an die Oberflaeche** -- wie beim Postfach
 * der Praxis. Ein leeres Feld heisst "unveraendert".
 */
final class VersandController extends Controller
{
    public function __construct(
        private readonly AuditLogger $protokoll,
        private readonly Farbpruefung $farben,
    ) {}

    public function edit(Request $request): Response
    {
        $einstellung = PlatformMailSetting::aktuell();
        $benutzer = $request->user();

        return Inertia::render('backoffice/Versand', [
            'server' => [
                'host' => $einstellung->smtp_host,
                'port' => $einstellung->smtp_port,
                'encryption' => $einstellung->smtp_encryption ?? ($einstellung->exists ? 'none' : 'tls'),
                // **Weder Benutzername noch Passwort gehen zurueck** (AK 17) --
                // nur, ob sie gesetzt sind. Ein leeres Feld heisst
                // "unveraendert".
                'benutzerGesetzt' => $einstellung->exists && $this->lesbar(fn (): ?string => $einstellung->smtp_username) !== null,
                'passwortGesetzt' => $einstellung->exists && $this->lesbar(fn (): ?string => $einstellung->smtp_password) !== null,
            ],
            'absender' => [
                'adresse' => $einstellung->from_address,
                'name' => $einstellung->from_name,
                'antwortAn' => $einstellung->reply_to_address,
            ],
            'aussehen' => [
                'farbe' => $einstellung->accent_color,
                'wirksameFarbe' => Markenstil::hexFuer($einstellung->accent_color) ?? (string) config('mrs.mail.produktfarbe'),
                'hatLogo' => is_string($einstellung->logo_path) && $einstellung->logo_path !== '',
                'logo' => is_string($einstellung->logo_path) && $einstellung->logo_path !== ''
                    ? route('mail.logo', ['fassung' => $einstellung->logo_version])
                    : null,
                'fusstext' => $einstellung->footer_text,
                'impressum' => $einstellung->imprint_url,
                'datenschutz' => $einstellung->privacy_url,
            ],
            'stand' => [
                'hinterlegt' => $einstellung->hatServer(),
                'gilt' => $einstellung->serverGilt(),
                'geprueftAm' => $einstellung->serverGilt() ? $einstellung->verified_at?->toIso8601String() : null,
                'stoerung' => $einstellung->last_error,
                'stoerungSeit' => $einstellung->failed_at?->toIso8601String(),
                // Was gilt, solange der hinterlegte Server nicht geprueft ist.
                'rueckfall' => [
                    'mailer' => (string) config('mail.default'),
                    'absender' => (string) config('mail.from.address'),
                ],
            ],
            'probeAn' => $benutzer instanceof User ? $benutzer->email : null,
            'grenzen' => [
                'fusstext' => (int) config('mrs.mail.laenge.fussnote'),
                'logoKb' => (int) config('mrs.mail.logo_max_kb'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            ...BackofficeController::PASSWORT,
            'smtp_host' => ['nullable', 'string', 'max:191'],
            'smtp_port' => ['nullable', 'required_with:smtp_host', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:191'],
            'smtp_password' => ['nullable', 'string', 'max:191'],
            'zugangsdaten_entfernen' => ['boolean'],
            'from_address' => ['nullable', 'required_with:smtp_host', 'email:rfc', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:191'],
            'reply_to_address' => ['nullable', 'email:rfc', 'max:191'],
            'accent_color' => ['nullable', 'string', 'max:7'],
            'footer_text' => ['nullable', 'string', 'max:'.(int) config('mrs.mail.laenge.fussnote')],
            // **Nur https**, wie bei der Praxis (WP-07).
            'imprint_url' => ['nullable', 'url:https', 'max:255'],
            'privacy_url' => ['nullable', 'url:https', 'max:255'],
        ], [
            'from_address.required_with' => 'Mit eigenem Server braucht es eine Absenderadresse, die dieser Server senden darf.',
            'smtp_port.required_with' => 'Bitte den Port angeben.',
        ]);

        $befund = $this->farben->pruefe(is_string($daten['accent_color'] ?? null) ? $daten['accent_color'] : null);

        if (! $befund->angenommen) {
            return back()->withErrors(['accent_color' => (string) $befund->ablehnung]);
        }

        if (preg_match('/<\s*[a-z!\/?]/iu', (string) ($daten['footer_text'] ?? '')) === 1) {
            return back()->withErrors(['footer_text' => 'HTML ist nicht erlaubt.']);
        }

        $einstellung = PlatformMailSetting::aktuell();
        $vorher = $this->serverstand($einstellung);

        $host = trim((string) ($daten['smtp_host'] ?? ''));
        $verschluesselung = (string) ($daten['smtp_encryption'] ?? 'tls');

        $einstellung->smtp_host = $host === '' ? null : $host;
        $einstellung->smtp_port = $host === '' ? null : (int) $daten['smtp_port'];
        $einstellung->smtp_encryption = $host === '' || $verschluesselung === 'none' ? null : $verschluesselung;

        if ($host === '' || ($daten['zugangsdaten_entfernen'] ?? false)) {
            $einstellung->smtp_username = null;
            $einstellung->smtp_password = null;
        } else {
            // Ein leeres Feld heisst "unveraendert", nicht "loeschen" -- fuer
            // beide Zugangsdaten, weil keine davon an die Oberflaeche geht.
            if (is_string($daten['smtp_username'] ?? null) && $daten['smtp_username'] !== '') {
                $einstellung->smtp_username = $daten['smtp_username'];
            }

            if (is_string($daten['smtp_password'] ?? null) && $daten['smtp_password'] !== '') {
                $einstellung->smtp_password = $daten['smtp_password'];
            }
        }

        $einstellung->from_address = ($daten['from_address'] ?? '') === '' ? null : mb_strtolower((string) $daten['from_address']);
        $einstellung->from_name = ($daten['from_name'] ?? '') === '' ? null : (string) $daten['from_name'];
        $einstellung->reply_to_address = ($daten['reply_to_address'] ?? '') === '' ? null : mb_strtolower((string) $daten['reply_to_address']);
        $einstellung->accent_color = $befund->farbe === '' ? null : $befund->farbe;
        $einstellung->footer_text = trim((string) ($daten['footer_text'] ?? '')) === '' ? null : trim((string) $daten['footer_text']);
        $einstellung->imprint_url = $daten['imprint_url'] ?? null;
        $einstellung->privacy_url = $daten['privacy_url'] ?? null;

        // **Jede Aenderung am Server macht ihn wieder ungeprueft** (AK 13).
        // Verglichen wird, was vorher galt -- das Passwort eingeschlossen,
        // ohne es irgendwo hinzuschreiben.
        if ($vorher !== $this->serverstand($einstellung)) {
            $einstellung->smtp_version = ($einstellung->smtp_version ?: 1) + 1;
            $einstellung->smtp_verified_version = null;
            $einstellung->verified_at = null;
            $einstellung->failed_at = null;
            $einstellung->last_error = null;
        }

        $benutzer = $request->user();
        $einstellung->updated_by_user_id = $benutzer instanceof User ? $benutzer->getKey() : null;

        $geaendert = array_keys($einstellung->getDirty());
        $einstellung->save();

        // **Nur Feldnamen** -- nie ein Wert, schon gar nicht ein Passwort (C5).
        $this->protokoll->record(
            AuditEvent::PlatformMailSettingsChanged,
            geaenderteFelder: array_values(array_diff($geaendert, ['updated_by_user_id', 'updated_at', 'created_at', 'id'])),
            ohneOrganisation: true,
        );

        return back()->with('erfolg', $einstellung->serverGilt() || ! $einstellung->hatServer()
            ? 'Gespeichert.'
            : 'Gespeichert. Der Server gilt, sobald die Probemail durchging — bis dahin gilt die Umgebung.');
    }

    /** Die Probemail -- ueber die Warteschlange, nur ueber den hinterlegten Server. */
    public function probe(Request $request): RedirectResponse
    {
        $einstellung = PlatformMailSetting::query()->first();
        $benutzer = $request->user();

        if (! $einstellung instanceof PlatformMailSetting || ! $einstellung->hatServer() || ! $benutzer instanceof User) {
            return back()->withErrors(['probe' => 'Zuerst einen Server hinterlegen.']);
        }

        PlattformversandPruefen::dispatch($einstellung->smtp_version, $benutzer->email);

        $this->protokoll->record(AuditEvent::PlatformMailTestRequested, ohneOrganisation: true);

        return back()->with('erfolg', "Die Probemail ist unterwegs an {$benutzer->email}. Das Ergebnis steht gleich hier.");
    }

    public function logo(Request $request): RedirectResponse
    {
        $request->validate([
            ...BackofficeController::PASSWORT,
            'datei' => [
                'required',
                'file',
                // **Nur PNG und JPEG**: Outlook zeigt kein WebP, und ein SVG
                // kann Skript tragen (WP-37 AK 10).
                'mimetypes:'.implode(',', (array) config('mrs.mail.logo_mimes')),
                'max:'.(int) config('mrs.mail.logo_max_kb'),
            ],
        ]);

        $datei = $request->file('datei');

        if (! $datei instanceof UploadedFile) {
            abort(422);
        }

        $einstellung = PlatformMailSetting::aktuell();
        $alt = $einstellung->logo_path;
        $mime = (string) $datei->getMimeType();
        $fassung = $einstellung->logo_version + 1;

        // **Unverschluesselt**: das Logo steht in jeder Mail offen und ist
        // kein Personendatum. Anhangspeicher arbeitet nur im Mandanten.
        $pfad = 'plattform/mail/logo-'.$fassung.($mime === 'image/png' ? '.png' : '.jpg');
        Storage::disk((string) config('mrs.attachments.disk'))->put($pfad, (string) file_get_contents($datei->getRealPath()));

        $einstellung->logo_path = $pfad;
        $einstellung->logo_mime = $mime;
        $einstellung->logo_version = $fassung;
        $einstellung->save();

        if (is_string($alt) && $alt !== '' && $alt !== $pfad) {
            Storage::disk((string) config('mrs.attachments.disk'))->delete($alt);
        }

        $this->protokoll->record(AuditEvent::PlatformMailLogoChanged, geaenderteFelder: ['logo_path'], ohneOrganisation: true);

        return back()->with('erfolg', 'Das Logo ist hinterlegt.');
    }

    public function logoEntfernen(Request $request): RedirectResponse
    {
        $request->validate(BackofficeController::PASSWORT);

        $einstellung = PlatformMailSetting::query()->first();

        if ($einstellung instanceof PlatformMailSetting && is_string($einstellung->logo_path) && $einstellung->logo_path !== '') {
            Storage::disk((string) config('mrs.attachments.disk'))->delete($einstellung->logo_path);

            $einstellung->logo_path = null;
            $einstellung->logo_mime = null;
            $einstellung->save();

            $this->protokoll->record(AuditEvent::PlatformMailLogoChanged, geaenderteFelder: ['logo_path'], ohneOrganisation: true);
        }

        return back()->with('erfolg', 'Das Logo ist entfernt.');
    }

    /**
     * Ein Fingerabdruck des Servers -- ob sich etwas geaendert hat, ohne das
     * Passwort festzuhalten.
     */
    private function serverstand(PlatformMailSetting $einstellung): string
    {
        return hash('sha256', implode("\0", [
            (string) $einstellung->smtp_host,
            (string) $einstellung->smtp_port,
            (string) $einstellung->smtp_encryption,
            (string) $this->lesbar(fn (): ?string => $einstellung->smtp_username),
            (string) $this->lesbar(fn (): ?string => $einstellung->smtp_password),
        ]));
    }

    /**
     * Ein verschluesseltes Feld -- oder null, wenn der App-Schluessel es nicht
     * mehr lesen kann (rotiert ohne APP_PREVIOUS_KEYS).
     *
     * @param  \Closure(): ?string  $lesen
     */
    private function lesbar(\Closure $lesen): ?string
    {
        try {
            $wert = $lesen();

            return is_string($wert) && $wert !== '' ? $wert : null;
        } catch (DecryptException) {
            return null;
        }
    }
}
