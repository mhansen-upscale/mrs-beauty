<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Anmeldung
|--------------------------------------------------------------------------
|
| Ueberschreibt die englischen Vorgaben des Frameworks. Entscheidung P7: nur
| Deutsch.
|
| Das ist kein Widerspruch zur Regel "Deutsch ohne Uebersetzungsschicht" aus
| docs/konventionen.md. Die gilt fuer **unsere** Oberflaechentexte, die direkt
| in der Komponente stehen. Was Laravel selbst erzeugt -- Validierungs-
| meldungen, Anmeldefehler, Mails -- laesst sich nur hier uebersetzen.
|
*/

return [
    // Bewusst unspezifisch: ob die Adresse ueberhaupt existiert, geht den
    // Anfragenden nichts an.
    'failed' => 'E-Mail-Adresse oder Passwort stimmen nicht.',
    'password' => 'Das Passwort stimmt nicht.',
    'throttle' => 'Zu viele Anmeldeversuche. Bitte in :seconds Sekunden erneut versuchen.',
];
