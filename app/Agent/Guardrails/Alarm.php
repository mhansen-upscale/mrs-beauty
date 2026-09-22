<?php

declare(strict_types=1);

namespace App\Agent\Guardrails;

use App\Enums\Ability;
use App\Enums\GuardrailHit;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Agentenalarm;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * Alarmiert das Team (Entscheidung G4).
 *
 * **Wer antworten darf, wird benachrichtigt.** Nicht die ganze Praxis: ein
 * Alarm, der alle erreicht, erreicht nach einer Woche niemanden mehr. Die
 * Faehigkeit `inbox.reply` ist die richtige Grenze -- wer eine Nachricht
 * beantworten kann, kann auch auf sie reagieren.
 */
final class Alarm
{
    public function __construct(private readonly TenantContext $mandant) {}

    public function schlage(Conversation $gespraech, GuardrailHit $grund): void
    {
        $organisation = $this->mandant->current();

        if (! $organisation instanceof Organization) {
            return;
        }

        // **users ist kein TenantModel**: der globale Scope schuetzt hier
        // niemanden, die Organisation muss in der Abfrage stehen.
        $empfaenger = User::query()
            ->derOrganisation((string) $organisation->getKey())
            ->whereNull('deactivated_at')
            ->get()
            ->filter(fn (User $benutzer): bool => $benutzer->role?->allows(Ability::ReplyInbox) ?? false);

        if ($empfaenger->isEmpty()) {
            return;
        }

        Notification::send($empfaenger, new Agentenalarm($gespraech, $grund, $organisation->name));
    }
}
