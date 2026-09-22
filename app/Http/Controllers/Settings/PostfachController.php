<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PostfachPruefen;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Postfach der Praxis.
 *
 * Zwei Adressen und ein Versandweg:
 *
 * - **Eingang** -- unsere Adresse. Die Praxis leitet ihre Post dorthin
 *   weiter; kein MX-Wechsel, keine Zugangsdaten noetig.
 * - **Absender** -- die Adresse der Praxis, unter der geantwortet wird.
 * - **Versand** -- ueber das eigene Postfach, wenn hinterlegt, sonst ueber
 *   den Versand der Plattform.
 *
 * **Warum das eigene Postfach der bessere Weg ist:** eine Mail mit der
 * Adresse der Praxis im Absender, die aus unserer Infrastruktur kommt,
 * besteht SPF und DKIM nur, wenn jemand die DNS-Eintraege der Domain
 * entsprechend gesetzt hat. Wer stattdessen seine Zugangsdaten eintraegt,
 * schickt die Antwort denselben Weg wie jede andere Mail seiner Praxis.
 */
final class PostfachController extends Controller
{
    public function edit(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $organisation = $mandant->current();
        $verbindung = $this->verbindung();

        $eingerichtet = $verbindung instanceof ChannelConnection;

        return Inertia::render('settings/Postfach', [
            'eingang' => $eingerichtet ? $verbindung->external_id : $this->vorschlagEingang($organisation),
            'eingerichtet' => $eingerichtet,
            'absender' => $verbindung?->sender_id,
            'anzeigename' => $eingerichtet && is_string($verbindung->display_name)
                ? $verbindung->display_name
                : $organisation?->name,
            'smtp' => [
                'host' => $verbindung?->smtp_host,
                'port' => $verbindung?->smtp_port,
                'encryption' => $eingerichtet && is_string($verbindung->smtp_encryption)
                    ? $verbindung->smtp_encryption
                    : 'tls',
                'username' => $verbindung?->smtp_username,
                // **Das Passwort geht nie zurueck an die Oberflaeche.** Ein
                // Feld, das es anzeigt, gibt es an jeden weiter, der den
                // Bildschirm sieht -- und an jeden Browser, der Formulare
                // speichert.
                'gesetzt' => is_string($verbindung?->smtp_password) && $verbindung->smtp_password !== '',
            ],
            'status' => $verbindung?->status->value,
            'statusLabel' => $verbindung?->status->label(),
            'letzterFehler' => $verbindung?->last_error,
            'geprueftAm' => $verbindung?->verified_at?->toIso8601String(),
            'probeAn' => Auth::user() instanceof User ? Auth::user()->email : null,
        ]);
    }

    public function update(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $daten = $request->validate([
            'absender' => ['required', 'email:rfc', 'max:191'],
            'anzeigename' => ['required', 'string', 'max:191'],
            'smtp_host' => ['nullable', 'string', 'max:191'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:191'],
            'smtp_password' => ['nullable', 'string', 'max:191'],
        ], [
            'absender.email' => 'Bitte eine gültige E-Mail-Adresse eintragen.',
        ]);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            return back();
        }

        $verbindung = $this->verbindung();

        if (! $verbindung instanceof ChannelConnection) {
            $verbindung = new ChannelConnection;
            $verbindung->channel = ChannelType::Email;
            $verbindung->status = ConnectionStatus::Active;

            // Die Eingangsadresse wird **einmal** vergeben und bleibt dann
            // stehen: sie steht auf Briefbogen und in Weiterleitungsregeln,
            // und eine Adresse, die sich aendert, verliert Post.
            $verbindung->external_id = $this->vorschlagEingang($organisation);
        }

        $verbindung->sender_id = mb_strtolower($daten['absender']);
        $verbindung->display_name = $daten['anzeigename'];
        $verbindung->smtp_host = $daten['smtp_host'] ?: null;
        $verbindung->smtp_port = $daten['smtp_port'] ?: null;
        $verbindung->smtp_encryption = ($daten['smtp_encryption'] ?? 'tls') === 'none' ? null : $daten['smtp_encryption'];
        $verbindung->smtp_username = $daten['smtp_username'] ?: null;

        // Ein leeres Feld heisst "unveraendert", nicht "loeschen": sonst
        // loescht jedes Speichern der uebrigen Angaben das Passwort mit.
        if (is_string($daten['smtp_password'] ?? null) && $daten['smtp_password'] !== '') {
            $verbindung->smtp_password = $daten['smtp_password'];
        }

        if ($verbindung->smtp_host === null) {
            $verbindung->smtp_password = null;
            $verbindung->smtp_username = null;
        }

        // Geaenderte Zugangsdaten sind ungeprueft, bis eine Mail durchging.
        $verbindung->verified_at = null;
        $verbindung->save();

        return back();
    }

    /**
     * Schickt eine Probemail -- **ueber die Queue** (Regel 4).
     *
     * Ein Mailserver, der nicht antwortet, laesst sonst diese Seite haengen.
     * Das Ergebnis steht danach an der Verbindung.
     */
    public function pruefen(TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $organisation = $mandant->current();
        $benutzer = Auth::user();

        if (! $organisation instanceof Organization || ! $benutzer instanceof User) {
            return back();
        }

        if (! $this->verbindung() instanceof ChannelConnection) {
            return back()->withErrors(['smtp_host' => 'Zuerst das Postfach speichern.']);
        }

        PostfachPruefen::dispatch((string) $organisation->uuid, (string) $benutzer->email);

        return back();
    }

    private function verbindung(): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('channel', ChannelType::Email->value)
            ->first();
    }

    /** `<slug>@<eingangsdomain>` -- vorgeschlagen, nicht erzwungen. */
    private function vorschlagEingang(?Organization $organisation): string
    {
        $domain = (string) config('mrs.channels.email.inbound_domain');

        $kennung = $organisation instanceof Organization ? $organisation->slug : 'praxis';

        return $kennung.'@'.$domain;
    }
}
