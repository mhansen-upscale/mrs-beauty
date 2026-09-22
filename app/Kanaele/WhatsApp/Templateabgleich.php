<?php

declare(strict_types=1);

namespace App\Kanaele\WhatsApp;

use App\Enums\MessageCostCategory;
use App\Enums\TemplateStatus;
use App\Kanaele\Kanalfehler;
use App\Models\ChannelConnection;
use App\Models\WhatsAppTemplate;
use App\Support\Fehlereinordnung;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Holt die Templates eines WhatsApp-Kontos.
 *
 * **Genehmigt wird bei Meta, gelesen wird hier.** Ein Template im Produkt
 * anzulegen, das dort nicht genehmigt ist, hiesse eine Nachricht anzubieten,
 * die beim Senden abgelehnt wird -- und zwar erst dann, wenn jemand sie
 * abschicken will.
 *
 * Gelesen wird unter der **WABA-Kennung** (`external_id`), nicht unter der
 * Rufnummer: Templates gehoeren zum Konto, nicht zur Nummer.
 */
final class Templateabgleich
{
    /**
     * @return array{angelegt: int, geaendert: int}
     */
    public function gleicheAb(ChannelConnection $verbindung): array
    {
        $angelegt = 0;
        $geaendert = 0;
        $jetzt = CarbonImmutable::now();

        foreach ($this->hole($verbindung) as $roh) {
            $name = data_get($roh, 'name');
            $sprache = data_get($roh, 'language');

            if (! is_string($name) || ! is_string($sprache)) {
                continue;
            }

            $template = WhatsAppTemplate::query()
                ->where('name', $name)
                ->where('language', $sprache)
                ->first();

            $neu = ! $template instanceof WhatsAppTemplate;

            if ($neu) {
                $template = new WhatsAppTemplate;
                $template->name = $name;
                $template->language = $sprache;
            }

            $rumpf = $this->rumpf($roh);

            $template->status = TemplateStatus::ausAntwort((string) data_get($roh, 'status', ''));
            $template->category = $this->kategorie(data_get($roh, 'category'));
            $template->body = $rumpf;
            $template->variables = $this->platzhalter($rumpf);
            $template->synced_at = $jetzt;

            $veraendert = $template->isDirty();
            $template->save();

            $angelegt += $neu ? 1 : 0;
            $geaendert += ! $neu && $veraendert ? 1 : 0;
        }

        return ['angelegt' => $angelegt, 'geaendert' => $geaendert];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hole(ChannelConnection $verbindung): array
    {
        $basis = rtrim((string) config('mrs.meta.graph_url'), '/');
        $version = (string) config('mrs.meta.api_version');

        $antwort = Http::withToken((string) $verbindung->access_token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException
                    && $ausnahme->response->serverError();
            }, throw: false)
            ->get($basis.'/'.$version.'/'.$verbindung->external_id.'/message_templates', ['limit' => 100]);

        if ($antwort->failed()) {
            throw new Kanalfehler(Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json()));
        }

        $daten = data_get($antwort->json(), 'data', []);

        return is_array($daten) ? array_values(array_filter($daten, is_array(...))) : [];
    }

    /**
     * Der Text des Rumpfes.
     *
     * Kopf, Fuss und Schaltflaechen bleiben draussen: sie tragen keine
     * Variablen, die wir setzen, und der Rumpf ist das, was in der Vorschau
     * steht.
     *
     * @param  array<string, mixed>  $roh
     */
    private function rumpf(array $roh): ?string
    {
        foreach ((array) data_get($roh, 'components', []) as $teil) {
            if (data_get($teil, 'type') === 'BODY' && is_string(data_get($teil, 'text'))) {
                return (string) data_get($teil, 'text');
            }
        }

        return null;
    }

    /**
     * Wie viele Platzhalter der Rumpf hat.
     *
     * Gezaehlt wird die hoechste Nummer und nicht die Zahl der Treffer:
     * {{1}} kann zweimal vorkommen, und dann sind es trotzdem eine Variable.
     */
    private function platzhalter(?string $rumpf): int
    {
        if ($rumpf === null) {
            return 0;
        }

        preg_match_all('/\{\{(\d+)\}\}/', $rumpf, $treffer);

        $nummern = array_map(intval(...), $treffer[1]);

        return $nummern === [] ? 0 : max($nummern);
    }

    /**
     * **Die Kategorie kommt von Meta**, auch hier. Utility statt Marketing
     * senkt die Kosten deutlich und haengt allein von der Formulierung ab --
     * beurteilt wird sie bei der Einreichung, nicht von uns.
     */
    private function kategorie(mixed $wert): MessageCostCategory
    {
        return match (is_string($wert) ? mb_strtolower($wert) : '') {
            'utility' => MessageCostCategory::Utility,
            'marketing' => MessageCostCategory::Marketing,
            'authentication' => MessageCostCategory::Authentication,
            default => MessageCostCategory::Service,
        };
    }
}
