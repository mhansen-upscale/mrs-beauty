<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageCostCategory;
use App\Enums\TemplateStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein bei Meta genehmigtes WhatsApp-Template.
 *
 * **Je Sprache getrennt genehmigt** (docs/integrationen/meta.md): derselbe
 * Name kann auf Deutsch stehen und auf Englisch abgelehnt sein. Deshalb ist
 * der Schluessel (Name, Sprache) und nicht der Name.
 *
 * Der Rumpf ist die Schablone mit {{1}}-Platzhaltern und traegt keinen
 * Personenbezug -- das Eingesetzte schon, und das steht verschluesselt an der
 * Nachricht.
 *
 * @property string $name
 * @property string $language
 * @property MessageCostCategory $category
 * @property TemplateStatus $status
 * @property string|null $body
 * @property int $variables
 * @property CarbonImmutable|null $synced_at
 */
class WhatsAppTemplate extends TenantModel
{
    use Auditable;

    protected $table = 'whatsapp_templates';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MessageCostCategory::class,
            'status' => TemplateStatus::class,
            'variables' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * Name, Sprache, Kategorie und Zustand duerfen mit Wert ins Protokoll:
     * keiner sagt etwas ueber eine Person (Entscheidung C5). Die Kategorie
     * gehoert hinein, weil sie die Kosten bestimmt.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['name', 'language', 'category', 'status'];
    }

    /**
     * @param  Builder<WhatsAppTemplate>  $query
     * @return Builder<WhatsAppTemplate>
     */
    public function scopeSendbar(Builder $query): Builder
    {
        return $query->where('status', TemplateStatus::Approved->value);
    }

    /**
     * Der Rumpf mit eingesetzten Variablen -- **nur fuer die Anzeige**.
     *
     * Gesendet wird der Name des Templates mit seinen Parametern; WhatsApp
     * setzt selbst ein. Wer stattdessen diesen Text schickte, bekaeme ihn
     * ausserhalb des Fensters abgelehnt.
     *
     * @param  list<string>  $werte
     */
    public function vorschau(array $werte): string
    {
        $text = (string) $this->body;

        foreach ($werte as $stelle => $wert) {
            $text = str_replace('{{'.($stelle + 1).'}}', $wert, $text);
        }

        return $text;
    }
}
