<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Die vier Warteschlangen (WP-33)
    |--------------------------------------------------------------------------
    |
    | Sie sind nicht gleichwertig, deshalb bekommt jede ein eigenes Profil
    | statt einer gemeinsamen Prioritaetenliste:
    |
    |   realtime     eingehende Webhooks und Agent-Laeufe. Eine Nachricht, die
    |                zehn Minuten hinter einem Insights-Sync wartet, ist fuer
    |                den Kontakt eine unbeantwortete Nachricht.
    |   default      alles Uebrige aus dem Produkt.
    |   sync         Kalender- und Meta-Abgleich. Lang, haeufig, darf warten.
    |   maintenance  Aufbewahrung, Aufraeumen, Aggregation, Bilderzeugung.
    |
    | 'tries' bleibt bei 1, wo geschrieben wird: schreibende
    | Fremdsystemaufrufe laufen ueber einen Idempotenzschluessel
    | (Entscheidung A13). Eine blinde Wiederholung ohne diesen Schluessel
    | wuerde doppelt senden -- und ein Bildauftrag ein zweites Mal bezahlen.
    |
    | --------------------------------------------------------------------------
    | **Diese Datei beschreibt, sie startet nichts.**
    |
    | Das Produkt laeuft auf der verwalteten Warteschlange von Laravel Cloud
    | (`QUEUE_CONNECTION=cloud`). Die Arbeiter werden dort in der Oberflaeche
    | eingerichtet, nicht hier -- ein Prozess je Warteschlange, mit genau
    | diesen Werten. Die Kommandos stehen woertlich in docs/betrieb.md.
    |
    | Wer hier etwas aendert, aendert es dort mit. Sonst laufen Anspruch und
    | Betrieb auseinander, und niemand sieht es. Der Preis dieser Trennung ist
    | benannt: kein Test kann pruefen, ob die Arbeiter wirklich laufen --
    | deshalb 'stillstand_minuten' und App\Betrieb\Warteschlangen.
    | --------------------------------------------------------------------------
    */

    'profile' => [

        'realtime' => [
            'tries' => 3,
            'timeout' => 60,
            'memory' => 128,
            'prozesse' => 10,

            // Eine Antwort an einen wartenden Menschen. Fuenf Minuten
            // Rueckstand sind hier schon eine Stoerung.
            'stillstand_minuten' => 5,
        ],

        'default' => [
            'tries' => 3,
            'timeout' => 60,
            'memory' => 128,
            'prozesse' => 6,
            'stillstand_minuten' => 15,
        ],

        'sync' => [
            'tries' => 1,
            'timeout' => 600,
            'memory' => 256,
            'prozesse' => 4,

            // Laeuft ohnehin im Stundentakt -- eine Stunde Ruhe ist hier
            // kein Stillstand, sondern der Normalfall.
            'stillstand_minuten' => 60,
        ],

        'maintenance' => [
            'tries' => 1,

            // **900 Sekunden sind nicht verhandelbar.** Der Bildauftrag
            // wartet bis zu 600 Sekunden auf kie.ai
            // (App\Jobs\AnzeigenbildErzeugen). Beim Vorgabewert von 60
            // Sekunden stirbt er mitten im Warten -- bezahlt und ohne
            // Ergebnis.
            'timeout' => 900,

            'memory' => 256,
            'prozesse' => 2,
            'stillstand_minuten' => 60,
        ],

    ],

];
