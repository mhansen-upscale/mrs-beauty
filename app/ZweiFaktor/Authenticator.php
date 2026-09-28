<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Models\User;
use App\Support\QrCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Die Authenticator-App: TOTP nach RFC 6238 (WP-35).
 *
 * **Die Zeit kommt immer von hier**, aus CarbonImmutable::now(). google2fa
 * liest sonst die Systemuhr und uebersieht die Testzeit -- die Tests
 * scheiterten dann zufaellig an der Taktgrenze.
 *
 * **Derselbe Code wirkt genau einmal.** Angenommen wird ein Code erst, wenn
 * ein einziges UPDATE den letzten Takt der Person auf seinen Takt hebt. Zwei
 * gleichzeitige Anmeldungen, die beide denselben Stand gelesen haben, kommen
 * so nicht beide durch: die zweite findet keine Zeile mehr, die sie heben
 * koennte. Das UPDATE laeuft am Modell vorbei und damit am allgemeinen
 * Protokoll -- ein Eintrag je Anmeldung waere Rauschen, keine Nachricht.
 */
final class Authenticator
{
    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
        $this->google2fa->setOneTimePasswordLength((int) config('mrs.zwei_faktor.code_stellen'));
        $this->google2fa->setKeyRegeneration((int) config('mrs.zwei_faktor.totp_takt_sekunden'));
        $this->google2fa->setWindow((int) config('mrs.zwei_faktor.totp_fenster'));
    }

    /** 32 Zeichen Base32, also 160 Bit -- so lang wie der HMAC-Schluessel von SHA-1. */
    public function neuesGeheimnis(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** Das Geheimnis in Vierergruppen, zum Abtippen. */
    public function lesbar(string $geheimnis): string
    {
        return trim(chunk_split($geheimnis, 4, ' '));
    }

    /**
     * Der QR-Code fuer die App, als Data-URL fuer ein `<img>`.
     *
     * Kein `v-html`: das SVG kommt zwar von uns, aber die Regel gilt ohne
     * Ausnahme (tests/Feature/Design/RegelFuenfTest.php).
     */
    public function qrCode(User $person, string $geheimnis): string
    {
        $adresse = $this->google2fa->getQRCodeUrl((string) config('app.name'), $person->email, $geheimnis);

        return 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($adresse, 200));
    }

    /**
     * Der Takt, zu dem der Code passt -- oder null.
     *
     * Nur Takte nach `$nach` zaehlen. Ohne Vorgaenger gilt jeder Takt im
     * Fenster.
     */
    public function passenderSchritt(string $geheimnis, string $code, ?int $nach = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.(int) config('mrs.zwei_faktor.code_stellen').'}$/', $code) !== 1) {
            return null;
        }

        $takt = (int) config('mrs.zwei_faktor.totp_takt_sekunden');
        $jetzt = intdiv(CarbonImmutable::now()->getTimestamp(), $takt);

        $schritt = $this->google2fa->verifyKeyNewer($geheimnis, $code, $nach ?? 0, null, $jetzt);

        return is_int($schritt) ? $schritt : null;
    }

    /**
     * Prueft den Code einer Person mit eingerichteter App und verbraucht ihn.
     */
    public function pruefe(User $person, string $code): bool
    {
        $geheimnis = $person->zwei_faktor_geheimnis;

        if (! is_string($geheimnis) || $geheimnis === '') {
            return false;
        }

        $schritt = $this->passenderSchritt($geheimnis, $code, $person->zwei_faktor_letzter_schritt);

        if ($schritt === null) {
            return false;
        }

        $gehoben = DB::table('users')
            ->where('id', $person->getKey())
            ->where(fn ($abfrage) => $abfrage
                ->whereNull('zwei_faktor_letzter_schritt')
                ->orWhere('zwei_faktor_letzter_schritt', '<', $schritt))
            ->update(['zwei_faktor_letzter_schritt' => $schritt]);

        if ($gehoben !== 1) {
            return false;
        }

        $person->zwei_faktor_letzter_schritt = $schritt;
        $person->syncOriginalAttribute('zwei_faktor_letzter_schritt');

        return true;
    }
}
