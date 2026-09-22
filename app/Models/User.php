<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\ImpersonationContext;
use App\Contracts\HasPersonalData;
use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBinaryUuid;
use App\Models\Concerns\MasksPersonalData;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * **Bewusst kein TenantModel**, obwohl die Tabelle eine organization_id traegt.
 * Die Anmeldung findet statt, bevor ein Mandant bekannt ist -- der Guard sucht
 * den Benutzer ueber die E-Mail-Adresse, und ein Global Scope wuerde dabei
 * einen Mandantenkontext verlangen, den es zu diesem Zeitpunkt nicht gibt.
 * Ausserdem gehoert der Super-Admin aus WP-34 zu keiner Organisation.
 *
 * Diese Ausnahme steht in der Zulassungsliste des Architektur-Tests. **WP-04
 * muss jede Auflistung von Benutzern ausdruecklich auf die Organisation
 * einschraenken** -- hier tut es niemand fuer einen.
 *
 * Das Interface ist nicht dekorativ. Ohne es laeuft das 'verified'-Middleware
 * auf dem Dashboard wirkungslos durch, der Registered-Listener verschickt
 * keine Bestaetigungsmail, und `new Verified($user)` verletzt den eigenen
 * Typvertrag des Ereignisses -- obwohl Routen, Controller und Tests fuer die
 * E-Mail-Bestaetigung vollstaendig vorhanden sind.
 *
 * Die Methoden selbst kommen ueber den MustVerifyEmail-Trait in
 * Illuminate\Foundation\Auth\User und waren die ganze Zeit da.
 *
 * @property string $id Rohbytes. Die lesbare Form ist $uuid.
 * @property-read string|null $uuid
 * @property string|null $organization_id
 * @property string $email
 * @property Role|null $role
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $email_verified_at
 */
class User extends Authenticatable implements HasPersonalData, MustVerifyEmail
{
    use Auditable;
    use HasBinaryUuid;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use MasksPersonalData;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',

        // Rohbytes. users ist kein TenantModel, also greift die Regel aus
        // BelongsToTenant hier nicht -- siehe tests/Feature/Schema/RohbytesTest.php.
        'organization_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'role' => Role::class,
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Rollen und Faehigkeiten (WP-04)
    |--------------------------------------------------------------------------
    */

    public function hasAbility(Ability $ability): bool
    {
        if ($this->isDeactivated()) {
            return false;
        }

        if ($this->handeltAlsSupport()) {
            return $this->faehigkeitAlsSupport($ability);
        }

        return $this->role?->allows($ability) ?? false;
    }

    /**
     * Was der Support waehrend einer Impersonation darf.
     *
     * Er sieht die Oberflaeche wie eine Inhaberin -- sonst waere eine
     * Impersonation nutzlos, denn ein Super-Admin hat in der fremden
     * Organisation keine Rolle. Zwei Faehigkeiten sind ausgenommen:
     *
     * - **Freigabe des Vollzugriffs.** Wer sich selbst freigeben koennte,
     *   haette Entscheidung C4 ausgehebelt. Das ist der Kern des Ganzen.
     * - **Abo und Abrechnung.** Geht den Support nichts an.
     */
    private function faehigkeitAlsSupport(Ability $ability): bool
    {
        if (in_array($ability, [Ability::ApproveImpersonation, Ability::ManageBilling], true)) {
            return false;
        }

        return Role::Owner->allows($ability);
    }

    private function handeltAlsSupport(): bool
    {
        if (! $this->isSuperAdmin()) {
            return false;
        }

        $sitzung = app(ImpersonationContext::class)->current();

        return $sitzung !== null
            && $sitzung->getAttribute('impersonator_user_id') === $this->getKey();
    }

    /**
     * Personenbezogene Felder. Werden bei maskierter Impersonation ersetzt.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['name', 'email'];
    }

    /**
     * Felder, deren Werte ins Protokoll duerfen -- ohne Personenbezug.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['role', 'deactivated_at', 'is_super_admin'];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->getAttribute('is_super_admin');
    }

    /** Nicht `is()` -- den Namen belegt Eloquent fuer den Modellvergleich. */
    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Ist das die letzte aktive Inhaberin ihrer Organisation?
     *
     * Ohne diese Sperre sperrt sich eine Praxis aus ihrem eigenen Produkt aus,
     * und niemand ausser dem Super-Admin kommt wieder hinein.
     */
    public function istLetzteInhaberin(): bool
    {
        if (! $this->hasRole(Role::Owner) || $this->organization_id === null) {
            return false;
        }

        return ! self::query()
            ->derOrganisation($this->organization_id)
            ->where('role', Role::Owner->value)
            ->whereNull('deactivated_at')
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Personen derselben Organisation.
     *
     * **users ist kein TenantModel** (siehe App\Models\User oben): der Global
     * Scope schuetzt hier niemanden. Jede Auflistung von Benutzern muss die
     * Organisation selbst einschraenken -- dieser Scope ist der Ort dafuer.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeDerOrganisation(Builder $query, ?string $organizationId = null): Builder
    {
        $id = $organizationId ?? app(TenantContext::class)->requireId(self::class);

        return $query->where('organization_id', $id);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
