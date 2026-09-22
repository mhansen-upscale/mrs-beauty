<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Wiederkehrende Aufgaben
|--------------------------------------------------------------------------
*/

// Zieht die Slots rollierend nach und raeumt abgelaufene Holds auf.
// Nachts, weil der Lauf ueber alle Mandanten geht und nichts davon eilt --
// die Slots von morgen stehen laengst.
// Laeuft im Planer selbst, nicht ueber eine Queue: der Lauf ist lang und
// soll sich nicht mit den Arbeitern um die Warteschlange 'maintenance'
// streiten. WP-33 entscheidet darueber abschliessend.
Schedule::command('mrs:slots-erzeugen')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping();

// Faellige Terminerinnerungen. Alle fuenf Minuten, weil eine Erinnerung, die
// eine halbe Stunde zu spaet kommt, bei einem Vorlauf von 24 Stunden niemanden
// stoert -- eine, die gar nicht kommt, dagegen schon.
//
// Der Befehl stellt nur ein, verschickt wird auf der Queue 'default'.
Schedule::command('mrs:erinnerungen-versenden')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Kalender-Abonnements. Stuendlich, weil "deutlich vor Ablauf" nur dann etwas
// heisst, wenn oft genug nachgesehen wird: ein Lauf am Tag und ein Vorlauf von
// 24 Stunden ergaeben genau einen Versuch je Abonnement.
Schedule::command('mrs:kalender-abos-erneuern')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// Vollabgleich als Netz unter dem Webhook. Eine ausgebliebene Zustellung faellt
// sonst erst auf, wenn jemand ueber einer belegten Zeit gebucht hat (R4).
// Nachts, und nach der Slot-Erzeugung: die Blocker sollen in Zeilen laufen,
// die es schon gibt.
Schedule::command('mrs:kalender-abgleichen')
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping();

// Aufbewahrungsfristen (Entscheidung C7). Taeglich, und **in der Vorschau**:
// scharf geschaltet wird von Hand, nachdem jemand die Zahlen gesehen hat. Ein
// Lauf, der zu viel loescht, ist nicht rueckholbar.
Schedule::command('mrs:aufbewahrung')
    ->dailyAt('04:15')
    ->onOneServer()
    ->withoutOverlapping();

// WhatsApp-Templates (WP-20a). Taeglich: eine Genehmigung kommt ohne
// Ankuendigung, eine Sperrung nach schlechter Qualitaetsbewertung erst recht.
// Wer den Stand nicht nachhaelt, bietet in der Inbox an, was WhatsApp nicht
// mehr annimmt.
Schedule::command('mrs:whatsapp-templates')
    ->dailyAt('04:45')
    ->onOneServer()
    ->withoutOverlapping();

// Wartelistenangebote (WP-25). Alle fuenf Minuten: ein Angebot gilt 30
// Minuten, und der naechste Kandidat soll nicht eine Stunde auf seine Runde
// warten. Der Lauf gibt abgelaufene Holds frei -- ohne ihn bliebe ein Slot
// gehalten, obwohl niemand ihn mehr will.
Schedule::command('mrs:warteliste-aufraeumen')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Werbestruktur (WP-26). Taeglich, vor der Aufbewahrung und nach den
// Templates: eine bei Meta pausierte Kampagne muss im Produkt pausiert
// aussehen, sonst erklaert sich eine ausbleibende Anfrage nicht.
//
// Der Lauf warnt zugleich vor ablaufenden Zugaengen -- ein Token, das am
// Ablauftag auffaellt, faellt zu spaet auf.
Schedule::command('mrs:werbung-abgleichen')
    ->dailyAt('05:15')
    ->onOneServer()
    ->withoutOverlapping();

// Metas Zahlen (WP-28). Nach dem Strukturabgleich: eine Zeile ohne ihre
// Kampagne waere eine Zahl ohne Namen.
//
// Geholt wird ein nachlaufendes Fenster, nicht der Vortag -- Metas
// Zuordnungsfenster wirkt bis zu 28 Tage rueckwirkend.
Schedule::command('mrs:werbung-zahlen')
    ->dailyAt('05:45')
    ->onOneServer()
    ->withoutOverlapping();

// Anzeigenvorschlaege (WP-31). Montags, damit die Praxis die Woche hat, um
// damit etwas zu tun.
//
// Auf dem Planer selbst, nicht ueber eine Queue: der Lauf ruft ein
// Sprachmodell je Praxis auf und soll sich nicht mit den Arbeitern um die
// Warteschlange streiten.
Schedule::command('mrs:anzeigen-vorschlagen')
    ->weeklyOn(1, '06:15')
    ->onOneServer()
    ->withoutOverlapping();
