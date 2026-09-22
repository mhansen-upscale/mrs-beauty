<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Models\ChannelIdentity;
use App\Models\Consent;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Einwilligungen erteilen, widerrufen und auswerten (Entscheidungen D8, D9).
 *
 * **Je Identitaet, nie je Person.** Ein WhatsApp-Opt-in haengt an einer
 * Rufnummer. Nach einer Zusammenfuehrung haengen zwei Nummern am selben
 * Kontakt -- und wenn eine davon widerrufen wurde, darf an sie nicht
 * geschrieben werden, auch wenn die andere zugestimmt hat. Genau das meint
 * D9 mit "nicht die Vereinigung".
 *
 * Deshalb gibt es hier **keinen** Weg, der zu einem Kontakt ein einzelnes Ja
 * oder Nein liefert. Wer senden will, fragt nach den Identitaeten, an die er
 * senden darf.
 */
final class Einwilligungen
{
    public function erteile(
        ChannelIdentity $identitaet,
        ConsentType $typ,
        string $textVersion,
        string $text,
        ?string $ip = null,
        ?string $browser = null,
        ?CarbonImmutable $jetzt = null,
    ): Consent {
        return $this->schreibe($identitaet, $typ, ConsentAction::Granted, $textVersion, $text, $ip, $browser, $jetzt);
    }

    public function widerrufe(
        ChannelIdentity $identitaet,
        ConsentType $typ,
        string $textVersion,
        string $text,
        ?CarbonImmutable $jetzt = null,
    ): Consent {
        return $this->schreibe($identitaet, $typ, ConsentAction::Revoked, $textVersion, $text, null, null, $jetzt);
    }

    /**
     * Der geltende Eintrag -- der juengste, und bei Gleichstand der Widerruf.
     *
     * Entscheidung D9. Der Gleichstand ist kein Sonderfall aus der Theorie:
     * ein Formular, das beim Absenden gleichzeitig eine alte Zustimmung
     * beendet und eine neue setzt, erzeugt ihn auf die Sekunde genau. Im
     * Zweifel gilt das Nein.
     */
    public function stand(ChannelIdentity $identitaet, ConsentType $typ): ?Consent
    {
        return Consent::query()
            ->where('channel_identity_id', $identitaet->getKey())
            ->where('type', $typ->value)
            ->orderByDesc('occurred_at')
            // 'revoked' < 'granted' alphabetisch -- aufsteigend steht der
            // Widerruf damit vorn. Ausgeschrieben, damit niemand es fuer
            // Zufall haelt.
            ->orderByRaw("field(action, 'revoked', 'granted')")
            ->first();
    }

    public function darfSenden(ChannelIdentity $identitaet, ConsentType $typ): bool
    {
        return $this->stand($identitaet, $typ)?->action === ConsentAction::Granted;
    }

    /**
     * Die Identitaeten eines Kontakts, an die gesendet werden darf.
     *
     * @return Collection<int, ChannelIdentity>
     */
    public function empfaenger(Contact $kontakt, ConsentType $typ): Collection
    {
        return $kontakt->channelIdentities()
            ->get()
            ->filter(fn (ChannelIdentity $identitaet): bool => $this->darfSenden($identitaet, $typ))
            ->values();
    }

    private function schreibe(
        ChannelIdentity $identitaet,
        ConsentType $typ,
        ConsentAction $richtung,
        string $textVersion,
        string $text,
        ?string $ip,
        ?string $browser,
        ?CarbonImmutable $jetzt,
    ): Consent {
        $eintrag = new Consent;
        $eintrag->channel_identity_id = $identitaet->getKey();
        $eintrag->type = $typ;
        $eintrag->action = $richtung;
        $eintrag->text_version = $textVersion;

        // Der Text selbst, nicht sein Name: die Erklaerung wird ueberarbeitet,
        // und wer nur die Version speichert, kann spaeter nicht mehr zeigen,
        // wozu jemand zugestimmt hat.
        $eintrag->text_snapshot = $text;
        $eintrag->ip_address = $ip;
        $eintrag->user_agent = $browser;
        $eintrag->occurred_at = $jetzt ?? CarbonImmutable::now();
        $eintrag->save();

        return $eintrag;
    }
}
