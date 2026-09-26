<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kontakte;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Kontakte\Notizbuch;
use App\Models\Contact;
use App\Models\Note;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Notizen und Schlagworte am Kontakt (offen seit WP-18) -- bedient aus dem
 * Posteingang, wo die Person gerade schreibt.
 */
final class NotizController extends Controller
{
    public function __construct(private readonly Notizbuch $buch) {}

    public function store(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $wer = $request->user();

        $this->buch->notiere($contact, (string) $daten['text'], $wer instanceof User ? $wer : null);

        return back();
    }

    public function destroy(Contact $contact, Note $note): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        abort_unless($this->buch->gehoertZu($note, $contact), 404);

        $note->delete();

        return back();
    }

    public function schlagwortStore(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $this->buch->vergib($contact, (string) $daten['name']);

        return back();
    }

    public function schlagwortDestroy(Contact $contact, Tag $tag): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $this->buch->entziehe($contact, $tag);

        return back();
    }
}
