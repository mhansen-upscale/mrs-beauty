<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fachliche Konstanten
|--------------------------------------------------------------------------
|
| Jeder Wert hier stammt aus einer verbindlichen Spezifikation unter
| specs/ bzw. docs/. Die Fundstelle steht als Kommentar daneben. Wer einen
| Wert aendert, aendert eine fachliche Zusage -- nicht eine Einstellung.
|
| Werte, die je Mandant abweichen duerfen, sind als "Standard" markiert und
| werden von organizations.settings ueberstimmt. Werte ohne diese Markierung
| gelten global und sind nicht mandantenkonfigurierbar.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Zeit
    |--------------------------------------------------------------------------
    |
    | Gespeichert wird in UTC. Jede fachliche Auswertung eines Datums oder
    | einer Uhrzeit -- Wochentagsmaske K6, Zeitfenster K7, Tagesgrenzen der
    | Verfuegbarkeit -- laeuft in der Ortszeit des Standorts. Diese Zone ist
    | nur der Rueckfall, wenn ein Standort keine eigene gesetzt hat.
    |
    */

    'business_timezone' => env('APP_BUSINESS_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Zugang (WP-04)
    |--------------------------------------------------------------------------
    |
    | docs/produkt.md fuehrt "Onboarding einer neuen Praxis" als ungeklaert.
    | Beide Wege sind gebaut, einer ist dieser Schalter.
    |
    | false: keine offene Registrierung. Praxen werden angelegt, das Team
    |        kommt ueber Einladungen herein.
    | true:  offene Registrierung legt Organisation und owner an.
    |
    | Der Standard ist die geschlossene Variante: Ein Produkt, das
    | HWG-Pruefungen fuer Aerzte ausspricht, will nicht, dass sich Beliebige
    | eine Praxis anlegen.
    |
    */

    'registration' => [
        'self_service' => (bool) env('REGISTRATION_SELF_SERVICE', false),
    ],

    'invitations' => [
        // Lebensdauer einer Einladung.
        'ttl_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Protokoll und Impersonation (WP-05)
    |--------------------------------------------------------------------------
    |
    | Entscheidungen C4, C5 und C7.
    |
    */

    'audit' => [
        // Entscheidung C7. Der Aufraeumjob gehoert zu WP-18; die Frist steht
        // hier, damit sie nur an einer Stelle liegt.
        'retention_months' => 36,
    ],

    'impersonation' => [
        // Eine maskierte Sitzung laeuft kurz. Support braucht Minuten, keine
        // Stunden.
        'masked_ttl_minutes' => 60,

        // Vollzugriff laeuft kuerzer und nur nach Freigabe (Entscheidung C4).
        'full_ttl_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent (WP-22 bis WP-24)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/fachlogik/agent.md
    |
    */

    'agent' => [

        // Not-Aus auf Installationsebene. Ueberstimmt jede Mandanten- und
        // Konversationseinstellung. docs/fachlogik/agent.md, "Not-Aus".
        'kill_switch' => (bool) env('AGENT_KILL_SWITCH', false),

        'model' => env('AGENT_MODEL', 'claude-sonnet-5'),

        // Schritt 5: unter diesem Wert wird eskaliert. Je Mandant senkbar,
        // aber nicht unter 'confidence_floor'.
        'confidence_threshold' => 0.7,
        'confidence_floor' => 0.5,

        // Schritt 5: nach so vielen automatischen Antworten hintereinander
        // ohne menschliche Beteiligung wird eskaliert.
        'max_consecutive_auto_replies' => 5,

        // Schritt 6: hoechstens so viele Klaerungsversuche je Zustand des
        // Buchungsautomaten, danach Eskalation.
        'max_clarifications_per_state' => 3,

        // Schritt 6: Lebensdauer des Slot-Holds im Buchungsdialog.
        'slot_hold_ttl_minutes' => 15,

        // Schritt 4: Wortstammsuche, kein Klassifikator. Diese Liste ist
        // nicht ueberstimmbar und loest Eskalation UND Alarm aus.
        // Falschauslösungen sind ausdruecklich erwuenscht.
        'complication_stems' => [
            'schwellung', 'geschwollen', 'fieber', 'blutung', 'blutet',
            'eiter', 'entzündung', 'entzuendung', 'entzündet', 'entzuendet',
            'taubheit', 'taub', 'schmerz', 'notfall', 'krankenhaus',
            'naht', 'wunde', 'nekrose', 'thrombose',
        ],

        // Schritt 7, Pruefung "Rabatt oder Aktion".
        'discount_stems' => [
            'rabatt', 'aktion', 'günstiger', 'guenstiger',
            'sonderpreis', 'kostenlos',
        ],

        // Schritt 7, Pruefung "Zusage".
        'promise_stems' => [
            'garantiert', 'auf jeden fall', 'verspreche', 'versprechen',
        ],

        // Die Antwort muss Deutsch sein. Schritt 7, Pruefung "Fremdsprache".
        'response_locale' => 'de',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verfuegbarkeit und Buchung (WP-10)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/fachlogik/verfuegbarkeit.md
    |
    */

    'booking' => [
        // Entscheidung A9. Jede Terminlaenge und jede Ruestzeit muss ein
        // Vielfaches davon sein, sonst bleiben Slot-Zeilen halb belegt.
        'slot_minutes' => 5,

        // Wie weit im Voraus Slots materialisiert werden. Der Job zieht
        // rollierend nach.
        'horizon_days' => 90,

        // Lebensdauer eines Holds auf der oeffentlichen Buchungsseite bzw.
        // in der internen Terminverwaltung.
        'hold_ttl_minutes' => 10,
        'internal_hold_ttl_minutes' => 10,

        // Anzeigeraster der oeffentlichen Buchungsseite (WP-12). Die Engine
        // rechnet in 'slot_minutes'; eine Liste mit "09:00, 09:05, 09:10 ..."
        // ist keine Auswahl, sondern eine Zumutung. Gemessen wird die
        // **angezeigte** Startzeit, nicht die belegte.
        'display_step_minutes' => 15,

        // Wie weit die oeffentliche Seite im Voraus zeigt. Mehr als der
        // Horizont waere eine leere Liste mit Blaetterfunktion.
        'public_days_shown' => 28,
    ],

    /*
    |--------------------------------------------------------------------------
    | Erinnerungen und Bestaetigungen (WP-13)
    |--------------------------------------------------------------------------
    |
    | Vorerst nur E-Mail. WhatsApp und SMS kosten Geld je Nachricht
    | (Entscheidungen B7 und B8) und setzen die Meta-Anbindung voraus
    | (WP-19, WP-20).
    |
    */

    'reminders' => [
        // Vorlauf der Terminerinnerung. Standard, je Mandant ueber
        // organizations.settings['reminders']['hours_before'] aenderbar.
        //
        // 24 Stunden: frueh genug, um den Tag noch umzuplanen, spaet genug,
        // um bis zum Termin im Kopf zu bleiben.
        'hours_before' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Warteliste (WP-25)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/fachlogik/warteliste.md
    |
    */

    'waitlist' => [

        // Gestaffelte Vergabe: Lebensdauer eines Angebots und des zugehoerigen
        // Slot-Holds. Standard, je Mandant ueberschreibbar.
        'offer_ttl_minutes' => 30,

        // Nach so vielen erfolglosen Runden bleibt der Slot offen.
        'max_rounds' => 5,

        // K10: Angebote je Kontakt und Kalendermonat. Schuetzt den Kanal und
        // die WhatsApp-Qualitaetsbewertung. Standard, je Mandant aenderbar.
        'max_offers_per_contact_per_month' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Leads (WP-17)
    |--------------------------------------------------------------------------
    |
    | Entscheidung D4: ein neuer Lead entsteht nur, wenn kein offener Lead
    | besteht, die Behandlung abweicht, oder so lange keine Aktivitaet war.
    | Ohne diese Regel entsteht entweder Lead-Inflation oder Lead-Verklumpung.
    |
    */

    'leads' => [
        'reopen_after_inactive_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribution (WP-32)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/fachlogik/attribution.md
    |
    */

    'attribution' => [

        // First-Party-Cookie der Buchungsseite und des Website-Snippets.
        'visitor_cookie_name' => 'mrs_vid',
        'visitor_cookie_days' => 180,

        // Rueckblickfenster nach Klick. Der Entscheidungsweg bei aesthetischen
        // Eingriffen ist lang -- ein kuerzeres Fenster ordnet systematisch zu
        // wenig zu. Konfigurierbar, der Hinweis dazu gehoert ins Dashboard.
        'lookback_days' => 28,

        'default_model' => 'last_non_direct',

        'models' => ['first_touch', 'last_touch', 'last_non_direct', 'linear'],

        // Entscheidung P9: nach zwoelf Monaten keine Aufschluesselung mehr
        // nach Anzeigengruppe und Einzelanzeige. Kampagnenebene bleibt.
        'ad_level_retention_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung (WP-18)
    |--------------------------------------------------------------------------
    |
    | Entscheidung C7. Standardwerte, je Mandant ueber retention_policies
    | konfigurierbar. Der Retention-Job hat einen Vorschaumodus -- ein Lauf,
    | der zu viel loescht, ist nicht rueckholbar.
    |
    */

    'retention' => [

        // Lead ohne Termin: loeschen.
        'lead_without_appointment_months' => 12,

        // Chat-Anhaenge: loeschen. 'expires_at' ist bei Chat-Anhaengen
        // Pflichtfeld (Entscheidung C6) -- ungefragt zugesandte Fotos.
        'chat_attachment_days' => 90,

        // Geschlossene Konversationen: anonymisieren, nicht loeschen.
        'conversation_anonymize_months' => 24,

        // Audit-Log: loeschen. Append-only, ohne Klartext-Personendaten (C5).
        'audit_log_months' => 36,

        // Snapshot einer Kontaktzusammenfuehrung: loeschen. Der Snapshot
        // enthaelt selbst Personendaten (Entscheidung D7).
        'merge_snapshot_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/integrationen/meta.md
    |
    */

    'meta' => [

        // Festgenagelt, nie aus dem SDK uebernommen. Halbjaehrliche Pruefung
        // gehoert zum Betrieb.
        'api_version' => env('META_API_VERSION', 'v21.0'),

        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        // Rohereignisse werden aufbewahrt, damit fehlgeschlagene Verarbeitungen
        // erneut eingespielt werden koennen.
        'raw_event_retention_days' => 14,

        // Service-Fenster fuer WhatsApp, Instagram und Messenger.
        'service_window_hours' => 24,

        // Erlaubte Conversions-API-Ereignisse. Abschliessend.
        'capi_allowed_events' => ['Lead', 'Schedule', 'Contact'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Kalendersync (WP-14, WP-15)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/integrationen/kalender.md
    |
    */

    'calendar' => [

        // R1: Schluessel der Eigenmarkierung. Ohne sie schreibt das System
        // seine eigenen Termine als externe Blocker zurueck.
        'marker_key' => 'mrs_beauty',

        // R2: neutraler Titel ausgehender Events. Kein Kontaktname, keine
        // Behandlung -- der Kalender liegt oft auf einem Privathandy.
        'outgoing_event_title' => 'Beratung',

        // Abonnements werden deutlich vor Ablauf erneuert, nicht kurz davor.
        'renew_before_expiry_hours' => 24,
    ],

];
