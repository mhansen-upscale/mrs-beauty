<?php

declare(strict_types=1);

namespace App\Models;

use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Enums\Mailart;
use App\Models\Concerns\HasBinaryUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Vorlage des Betreibers fuer eine Produktmail (WP-37).
 *
 * **Global, ohne Mandant**: Adresse bestaetigen, Passwort, Einladung, Alarm
 * und Code sind Mails des Produkts, fuer alle Praxen gleich. Ueberschreibung
 * wie bei der Praxis (D15).
 *
 * @property Mailart $template
 * @property string $subject
 * @property string $greeting
 * @property string $intro
 * @property string $outro
 * @property string $salutation
 * @property CarbonImmutable|null $updated_at
 * @property string|null $updated_by_user_id
 */
class PlatformMailTemplate extends Model
{
    use HasBinaryUuid;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['updated_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => Mailart::class,
        ];
    }

    public function text(): Mailtext
    {
        return Mailtext::aus($this->only(['subject', 'greeting', 'intro', 'outro', 'salutation']));
    }
}
