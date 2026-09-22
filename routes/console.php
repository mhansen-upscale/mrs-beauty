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
