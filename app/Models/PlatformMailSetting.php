<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBinaryUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Der Versand der Plattform: Server, Absender und Aussehen der Produktmails
 * (WP-37, B23).
 *
 * **Genau eine Zeile, global.** Sie gehoert dem Betreiber, keiner Praxis --
 * deshalb der App-Schluessel (`encrypted`) statt des Schluessels je
 * Organisation, den es hier nicht gibt.
 *
 * **Gespeichert ist nicht geprueft.** `smtp_version` zaehlt jede Aenderung an
 * Server und Zugangsdaten; der Server gilt erst, wenn `smtp_verified_version`
 * dieselbe Fassung nennt. Bis dahin gilt `.env`.
 *
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $reply_to_address
 * @property string|null $accent_color
 * @property string|null $logo_path
 * @property string|null $logo_mime
 * @property int $logo_version
 * @property string|null $footer_text
 * @property string|null $imprint_url
 * @property string|null $privacy_url
 * @property int $smtp_version
 * @property int|null $smtp_verified_version
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error
 * @property string|null $updated_by_user_id
 */
class PlatformMailSetting extends Model
{
    use HasBinaryUuid;

    /** Felder, deren Aenderung den Server wieder ungeprueft macht. */
    public const SERVERFELDER = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'];

    protected $guarded = ['id', 'singleton'];

    /** @var list<string> */
    protected $hidden = ['smtp_username', 'smtp_password', 'updated_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_username' => 'encrypted',
            'smtp_password' => 'encrypted',
            'logo_version' => 'integer',
            'smtp_version' => 'integer',
            'smtp_verified_version' => 'integer',
            'verified_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** Die eine Zeile -- ungespeichert, solange niemand etwas hinterlegt hat. */
    public static function aktuell(): self
    {
        return self::query()->first() ?? new self([
            'smtp_version' => 1,
            'logo_version' => 0,
        ]);
    }

    public function hatServer(): bool
    {
        return is_string($this->smtp_host) && $this->smtp_host !== '' && $this->smtp_port !== null;
    }

    /** Gilt der hinterlegte Server -- also ging eine Probe ueber genau diese Fassung? */
    public function serverGilt(): bool
    {
        return $this->hatServer()
            && $this->smtp_verified_version !== null
            && $this->smtp_verified_version === $this->smtp_version;
    }

    /** Vermerkt eine Stoerung -- ohne Klartext des Servers. */
    public function meldeStoerung(string $grund): void
    {
        if (! $this->exists) {
            return;
        }

        $this->forceFill([
            'failed_at' => CarbonImmutable::now(),
            'last_error' => mb_substr($grund, 0, 64),
        ])->saveQuietly();
    }
}
