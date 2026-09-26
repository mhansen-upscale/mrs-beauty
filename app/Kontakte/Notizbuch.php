<?php

declare(strict_types=1);

namespace App\Kontakte;

use App\Models\Contact;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Notizen und Schlagworte am Kontakt (offen seit WP-18).
 *
 * **Kein Freitextfeld am Kontakt**, sondern eigene Zeilen: jede mit Verfasser,
 * Zeitpunkt und Frist, verschluesselt, und in der Auskunft (Art. 15) wie in der
 * Loeschung mit dabei. Ein Notizfeld ohne eigenen Ort fuellt sich trotzdem --
 * nur ohne das alles.
 */
final class Notizbuch
{
    /**
     * @return Collection<int, Note>
     */
    public function notizen(Contact $kontakt): Collection
    {
        return Note::query()
            ->where('notable_type', Contact::class)
            ->where('notable_id', $kontakt->getKey())
            ->with('author')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function notiere(Contact $kontakt, string $text, ?User $wer): Note
    {
        $notiz = new Note;
        $notiz->notable_type = Contact::class;
        $notiz->notable_id = $kontakt->getKey();
        $notiz->body = trim($text);
        $notiz->author_user_id = $wer?->getKey();
        $notiz->save();

        return $notiz;
    }

    /** Gehoert diese Notiz zu diesem Kontakt? Sonst gibt es sie hier nicht. */
    public function gehoertZu(Note $notiz, Contact $kontakt): bool
    {
        return $notiz->notable_type === Contact::class && $notiz->notable_id === $kontakt->getKey();
    }

    /**
     * @return Collection<int, Tag>
     */
    public function schlagworte(Contact $kontakt): Collection
    {
        return Tag::query()
            ->whereIn('id', Taggable::query()
                ->where('taggable_type', Contact::class)
                ->where('taggable_id', $kontakt->getKey())
                ->select('tag_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Vergibt ein Schlagwort -- und nimmt ein vorhandenes, wenn es denselben
     * Namen schon gibt. "Stammkundin" und "stammkundin " sind eines.
     */
    public function vergib(Contact $kontakt, string $name): Tag
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        $schlagwort = Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? Tag::query()->create(['name' => $name]);

        Taggable::query()->firstOrCreate([
            'tag_id' => $schlagwort->getKey(),
            'taggable_type' => Contact::class,
            'taggable_id' => $kontakt->getKey(),
        ]);

        return $schlagwort;
    }

    public function entziehe(Contact $kontakt, Tag $schlagwort): void
    {
        Taggable::query()
            ->where('tag_id', $schlagwort->getKey())
            ->where('taggable_type', Contact::class)
            ->where('taggable_id', $kontakt->getKey())
            ->delete();
    }
}
