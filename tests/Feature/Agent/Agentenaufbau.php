<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\Sprachmodell;
use App\Enums\AgentMode;
use App\Enums\ChannelType;
use App\Kanaele\Konversationen;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Eine Praxis mit einem Gespraech und einem vorhersagbaren Modell.
 */
final class Agentenaufbau
{
    public readonly Organization $organisation;

    public readonly Conversation $gespraech;

    public readonly Testmodell $modell;

    /**
     * @param  list<string>  $antworten
     */
    public function __construct(array $antworten = [], AgentMode $modus = AgentMode::Suggest)
    {
        $this->organisation = alsMandant(Organization::factory()->create([
            'name' => 'Demo-Praxis',
            'slug' => 'demo-praxis',
        ]));

        $this->modell = new Testmodell($antworten);

        app()->instance(Sprachmodell::class, $this->modell);

        $identitaet = ChannelIdentity::create([
            'channel' => ChannelType::WhatsApp,
            'external_id' => '4915112345678',
            'display_name' => 'Annika Müller',
        ]);

        $gespraech = app(Konversationen::class)->fuer($identitaet);
        $gespraech->agent_mode = $modus;
        $gespraech->save();

        $this->gespraech = $gespraech->fresh() ?? $gespraech;
    }

    /** Eine eingehende Nachricht -- ohne den Agenten anzustossen. */
    public function nachricht(string $text, ?string $betreff = null): Message
    {
        $nachricht = app(Konversationen::class)->nimmAuf(
            $this->gespraech->fresh() ?? $this->gespraech,
            'ext-'.bin2hex(random_bytes(4)),
            $text,
            null,
            CarbonImmutable::now(),
            $betreff,
        );

        return $nachricht ?? throw new RuntimeException('Nachricht nicht angelegt.');
    }
}
