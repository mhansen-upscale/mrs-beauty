<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\EncryptionKey;
use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\Exceptions\KeyRevoked;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Envelope Encryption (Entscheidung A5).
 *
 *   APP_KEY (Key Encryption Key)
 *      └── umschliesst  wrapped_dek        je Organisation
 *      └── umschliesst  wrapped_index_key  je Organisation
 *
 * Kuendigt ein Mandant, wird sein Schluessel widerrufen und seine Daten sind
 * unlesbar -- ohne eine einzige Zeile anzufassen. Die Betriebsbedingung dazu
 * steht in docs/datenmodell.md, Abschnitt 0.6: encryption_keys braucht eine
 * eigene Aufbewahrungsregel, sonst holt eine Ruecksicherung den Schluessel
 * zurueck.
 */
// Nicht final: der Testfall "legt nichts an, wenn ein Schritt fehlschlaegt"
// tauscht die Klasse aus, um einen Fehler mitten in der Transaktion zu
// erzwingen. Ohne diesen Weg liesse sich der Rollback nur ueber DDL ausloesen
// -- und DDL loest in MySQL ein implizites Commit aus, das die Transaktion
// des Tests gleich mit beendet.
class KeyRing
{
    private const SCHLUESSELLAENGE = 32;

    /** @var array<string, OrganizationKeys> Zwischenspeicher je Anfrage, nie persistent. */
    private array $entpackt = [];

    /**
     * Legt einen Schluesselsatz an. Einmal je Organisation.
     */
    public function issue(Organization $organization): EncryptionKey
    {
        $dek = random_bytes(self::SCHLUESSELLAENGE);
        $indexKey = random_bytes(self::SCHLUESSELLAENGE);

        $schluessel = new EncryptionKey;
        $schluessel->organization_id = $organization->getKey();
        $schluessel->wrapped_dek = Crypt::encryptString(base64_encode($dek));
        $schluessel->wrapped_index_key = Crypt::encryptString(base64_encode($indexKey));
        $schluessel->save();

        $this->entpackt[$organization->getKey()] = new OrganizationKeys($dek, $indexKey);

        return $schluessel;
    }

    /**
     * Krypto-Loeschung. Die Zeile bleibt als Nachweis stehen, der Inhalt wird
     * unbrauchbar gemacht -- ein blosses revoked_at genuegt nicht, solange der
     * umschlossene Schluessel noch danebensteht.
     */
    public function revoke(Organization $organization, string $grund): void
    {
        DB::transaction(function () use ($organization, $grund): void {
            EncryptionKey::query()
                ->where('organization_id', $organization->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'wrapped_dek' => '',
                    'wrapped_index_key' => '',
                    'revoked_at' => now(),
                    'revoked_reason' => $grund,
                ]);
        });

        unset($this->entpackt[$organization->getKey()]);
    }

    /**
     * Die entpackten Schluessel einer Organisation.
     *
     * @param  string  $organizationId  binaer oder kanonisch
     */
    public function for(string $organizationId): OrganizationKeys
    {
        $id = Uuid::normalize($organizationId);

        if (isset($this->entpackt[$id])) {
            return $this->entpackt[$id];
        }

        $schluessel = EncryptionKey::query()
            ->where('organization_id', $id)
            ->whereNull('revoked_at')
            ->first();

        if (! $schluessel instanceof EncryptionKey || $schluessel->wrapped_dek === '') {
            throw KeyRevoked::fuer(Uuid::toString($id));
        }

        return $this->entpackt[$id] = new OrganizationKeys(
            (string) base64_decode(Crypt::decryptString($schluessel->wrapped_dek), true),
            (string) base64_decode(Crypt::decryptString($schluessel->wrapped_index_key), true),
        );
    }

    /** Vergisst die entpackten Schluessel. Fuer lang laufende Prozesse. */
    public function flush(): void
    {
        $this->entpackt = [];
    }
}
