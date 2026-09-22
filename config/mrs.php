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

        // Preis je einer Million Token, in **Zehntel-US-Cent**. Quelle:
        // Preisliste des Anbieters, Stand 16.09.2026. Ein Aufruf kostet
        // regelmaessig weniger als einen Cent -- eine Rechnung, die auf null
        // rundet, sagt nichts.
        //
        // Die Umrechnung in Euro und die Abrechnung im Abo sind WP-06; hier
        // wird nur gezaehlt.
        // Ein US-Dollar sind 1.000 Zehntel-Cent: 3 Dollar je Million
        // Eingabetoken stehen also als 3_000 da.
        'model_pricing' => [
            'claude-sonnet-5' => ['input' => 3_000, 'output' => 15_000],
            'claude-opus-5' => ['input' => 15_000, 'output' => 75_000],
            'claude-haiku-4-5-20251001' => ['input' => 1_000, 'output' => 5_000],
        ],

        // Schritt 5: unter diesem Wert wird eskaliert. Je Mandant senkbar,
        // aber nicht unter 'confidence_floor'.
        'confidence_threshold' => 0.7,
        'confidence_floor' => 0.5,

        // Schritt 5: nach so vielen automatischen Antworten hintereinander
        // ohne menschliche Beteiligung wird eskaliert.
        'max_consecutive_auto_replies' => 5,

        // **Kontingent je Mandant und Monat** (Entscheidung G11), in
        // Zehntel-US-Cent wie 'model_pricing'.
        //
        // Ein Durchlauf sind zwei Aufrufe -- Einordnung und Entwurf -- mit
        // zusammen rund 2.700 Eingabe- und 260 Ausgabetoken. Mit Sonnet sind
        // das etwa 12 Zehntel-Cent, also gut ein US-Cent. 7.500 reichen damit
        // fuer rund 600 Nachrichten im Monat; eine Praxis mit reger
        // Kommunikation stockt auf.
        'monthly_budget_tenth_cents' => 7_500,

        // Was ein Durchlauf im Schnitt kostet -- fuer die Anzeige "noch etwa
        // 600 Nachrichten". Sobald ein Mandant eigene Laeufe hat, zaehlt sein
        // eigener Schnitt; eine Praxis mit langen Nachrichten hat andere
        // Kosten als eine mit kurzen.
        'average_run_tenth_cents' => 12,

        // Ein Block beim Aufstocken -- und wie viele davon ein Monat
        // hoechstens vertraegt. Die Obergrenze ist kein Misstrauen gegen
        // Kunden, sondern gegen Fehler: ein Knopf, der versehentlich
        // zwanzigmal gedrueckt wird, soll keine Rechnung erzeugen.
        'topup_block_tenth_cents' => 7_500,
        'max_topups_per_month' => 10,

        // Ab diesem Anteil weist das Produkt auf das knappe Kontingent hin.
        'budget_warning_ratio' => 0.8,

        // Nach einer harten Eskalation -- Komplikation, Beschwerde, Bild --
        // haelt sich der Agent so lange aus dem Gespraech heraus. Aufheben
        // kann das jeder, der den Modus umstellen darf. Ein Agent, der nach
        // einer Komplikationsmeldung bei der naechsten Nachricht weitermacht,
        // als waere nichts gewesen, ist schlimmer als keiner.
        'escalation_pause_hours' => 24,

        // Schritt 6: hoechstens so viele Klaerungsversuche je Zustand des
        // Buchungsautomaten, danach Eskalation.
        'max_clarifications_per_state' => 3,

        // Schritt 6: kurze Antworten im Buchungsdialog, gedeutet ohne Modell
        // -- eine Wortliste trifft das zuverlaessiger als ein Aufruf, und sie
        // ist eine Stelle weniger, an der eine fremde Nachricht etwas
        // ausloesen koennte. Im Zweifel gilt: keine Zustimmung.
        'affirmations' => [
            'ja', 'jap', 'jo', 'gern', 'gerne', 'passt', 'einverstanden',
            'okay', 'ok', 'in ordnung', 'bitte', 'klar', 'perfekt',
        ],

        'negations' => [
            'nein', 'ne', 'nö', 'noe', 'lieber nicht', 'passt nicht',
            'kein interesse', 'doch nicht',
        ],

        // Wie weit voraus der Agent Termine vorschlaegt und wie viele.
        'proposal_days' => 14,
        'proposal_count' => 3,

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

        // Schritt 7, Pruefung "Medizinische Aussage". Bewusst weit gefasst:
        // der Agent ist eine Empfangskraft und hat zu diesen Dingen nichts zu
        // sagen, auch nichts Harmloses.
        'medical_stems' => [
            'geeignet', 'eignung', 'risiko', 'risiken', 'nebenwirkung',
            'wirkstoff', 'dosierung', 'dosis', 'einheiten', 'heilung',
            'heilungsdauer', 'abschwellen', 'nachsorge', 'betäubung',
            'betaeubung', 'unbedenklich', 'vertraeglich', 'verträglich',
            'allergie', 'schwanger', 'stillzeit', 'blutverduennend',
            'blutverdünnend',
        ],

        // Schritt 7, Pruefung "Behandlungsname ausserhalb des Katalogs".
        // Begriffe, die nach einer Leistung klingen -- steht der Begriff in
        // keinem Katalognamen, hat der Agent sie erfunden.
        'treatment_terms' => [
            'botox', 'hyaluron', 'filler', 'lidstraffung', 'fadenlifting',
            'microneedling', 'peeling', 'laser', 'kryolipolyse',
            'lippenunterspritzung', 'bruststraffung', 'fettabsaugung',
            'haartransplantation', 'nasenkorrektur', 'faltenbehandlung',
        ],

        // Schritt 7, Pruefung "Fremdsprache". Gebraeuchliche deutsche
        // Woerter; kommt keines davon vor, ist die Antwort keines.
        'german_markers' => [
            'der', 'die', 'das', 'und', 'ist', 'sie', 'wir', 'ihnen', 'ihre',
            'nicht', 'gern', 'termin', 'uns', 'für', 'mit', 'bei', 'wie',
        ],

        // Schritt 7, Pruefung "Zusage".
        'promise_stems' => [
            'garantiert', 'auf jeden fall', 'verspreche', 'versprechen',
        ],

        // Entscheidung G6: Kennzeichnung bei der ersten automatischen
        // Antwort je Konversation. Transparenzpflicht nach EU AI Act, keine
        // Stilfrage. Bei `suggest` entfaellt sie, weil ein Mensch sendet.
        'disclosure' => 'Hinweis: Diese Nachricht wurde von einem KI-Assistenten unserer Praxis geschrieben.',

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
    | Kontakte (WP-16)
    |--------------------------------------------------------------------------
    |
    | Die Region, in der eine Telefonnummer ohne Laendervorwahl gelesen wird.
    | "0170 1234567" ist ohne diese Angabe keine vollstaendige Nummer -- und
    | ohne kanonische Form gibt es keinen blinden Index, der trifft.
    |
    | Global, nicht je Mandant: eine Praxis in Hamburg und eine in Muenchen
    | lesen dieselbe Nummer gleich. Fuer den Fall, dass das Produkt einmal
    | ueber die Landesgrenze geht, steht der Wert hier und nicht im Code.
    |
    */

    'contacts' => [
        'default_region' => env('CONTACTS_DEFAULT_REGION', 'DE'),

        // Entscheidung D7: der Snapshot einer Zusammenfuehrung enthaelt
        // selbst Personendaten. Danach ist der Vorgang sichtbar, aber nicht
        // mehr umkehrbar. Der Aufraeumjob gehoert zu WP-18; die Frist steht
        // hier, damit sie nur an einer Stelle liegt (vgl. retention).
        'merge_snapshot_days' => 30,
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

        // **Ohne Einwilligung keines** (Paragraf 25 TTDSG, WP-32a). Ein
        // Wiedererkennungs-Cookie mit 180 Tagen Laufzeit ist nicht technisch
        // notwendig -- die Einwilligung muss vorliegen, bevor es gesetzt
        // wird, nicht waehrend. Dasselbe gilt fuer das Meta-Pixel, das auf
        // der Buchungsseite bis dahin ungefragt feuerte.
        'consent_cookie' => 'mrs_einwilligung',

        // Aufbewahrung nicht verknuepfter Touches in Tagen (C7): wer nie zu
        // einem Lead wurde, ist eine Zeile ohne Zweck.
        'touch_retention_days' => 180,

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
    | Anhaenge (WP-18)
    |--------------------------------------------------------------------------
    |
    | Die Platte, auf der Anhaenge liegen. Der Inhalt ist mit dem Schluessel
    | der Organisation verschluesselt (Entscheidung A6) -- ein Speicherabzug
    | ohne Schluesselsatz ist damit wertlos.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),

        // **Die Virenpruefung ist Infrastruktur** (WP-33). Ohne Host ist
        // keine angebunden -- dann wird jeder Anhang als `unscanned`
        // vermerkt und **nicht** ausgeliefert. Das ist unbequem und ehrlich:
        // was niemand geprueft hat, wird nicht weitergereicht.
        'scanner' => [
            'host' => env('CLAMAV_HOST'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout_seconds' => (int) env('CLAMAV_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Abo und Abrechnung (WP-06)
    |--------------------------------------------------------------------------
    |
    | **Eine Stufe je Praxis** (Entscheidung B10), mit enthaltenen Mengen.
    | Was darueber hinaus geht, wird aufgestockt -- und begrenzt wird nur,
    | was Geld kostet (B12): eine Antwort im offenen Service-Fenster geht
    | immer hinaus.
    |
    | Angezeigt wird in Mengen, nicht in Cent (B11).
    |
    */

    'billing' => [

        'trial_days' => 30,

        // Was der Grundpreis enthaelt, je Monat.
        'included' => [
            // Kostenpflichtige Nachrichten -- also Templates ausserhalb des
            // Service-Fensters. Antworten im Fenster zaehlen nicht mit.
            'messages' => 250,

            // Laeufe des Assistenten mit Modellaufruf.
            'agent_runs' => 600,

            // Erzeugte Anzeigenbilder (WP-31). **Ein eigener Zaehler**: ein
            // Bild kostet ein Vielfaches eines Textlaufs, und beides in
            // einen Topf zu werfen hiesse, dass ein paar Bilder den
            // Assistenten fuer den Rest des Monats verstummen lassen.
            //
            // Betreiberentscheidung vom 20.09.2026: zunaechst 15, noch am
            // selben Tag auf **30** erhoeht. Gezaehlt wird jede erzeugte
            // Datei, nicht jeder Entwurf -- und die zweite Fassung einer
            // Grafik ist der Normalfall, nicht die Ausnahme: das Bildmodell
            // verschreibt sich bei deutscher Schrift.
            'images' => 30,
        ],

        // Was ein Block beim Aufstocken bringt.
        'topup' => [
            'messages' => 250,
            'agent_runs' => 600,

            // **Bilder werden einzeln nachgekauft**, nicht in Bloecken: bei
            // zwei Euro das Stueck waere ein Block von 250 eine Rechnung
            // ueber 500 Euro, die niemand wollte.
            'images' => 1,
        ],

        // Preis je zusaetzlichem Anzeigenbild, in Cent (Entscheidung B13).
        // Steht hier, damit die Oberflaeche ihn nennen kann -- abgerechnet
        // wird bei Stripe.
        'image_price_cents' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kanaele (WP-20)
    |--------------------------------------------------------------------------
    |
    | Bedient werden zwei: WhatsApp und E-Mail (Entscheidung P11). Was zu
    | WhatsApp gehoert, steht unter 'meta' -- Fenster, Version, Geheimnisse.
    |
    */

    'channels' => [

        'email' => [

            // Die Adresse, unter der eine Praxis ihre Post empfaengt, ist
            // <kennung>@<dieser Domain>. Sie steht an der Verbindung; die
            // Domain hier ist nur die Vorgabe beim Einrichten.
            'inbound_domain' => env('MAIL_INBOUND_DOMAIN', 'inbound.mrs-beauty.de'),

            // Der Eingangsdienst weist sich damit aus. **Kein Ersatz fuer
            // eine Signatur**, aber die Zustellung kommt von unserem eigenen
            // Relay und nicht von einem Fremdsystem.
            'inbound_token' => env('MAIL_INBOUND_TOKEN'),

            // Groesser als das Postfach der meisten Praxen erlaubt. Was
            // darueber liegt, bleibt im Rohereignis und ist nach 14 Tagen weg
            // -- die Alternative waere ein Speicher, den eine einzige Mail
            // fuellen kann.
            'max_attachment_bytes' => 10 * 1024 * 1024,

            // Wenn ein Gespraech keinen Betreff hat: eine Mail ohne Betreff
            // landet in manchen Postfaechern im Spam.
            'default_subject' => 'Ihre Nachricht an uns',
        ],
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

        // Die Adresse steht in der Konfiguration, damit ein Test gegen eine
        // eigene Gegenstelle laeuft und nicht gegen Meta.
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        // Rohereignisse werden aufbewahrt, damit fehlgeschlagene Verarbeitungen
        // erneut eingespielt werden koennen.
        'raw_event_retention_days' => 14,

        // Service-Fenster fuer WhatsApp, Instagram und Messenger.
        'service_window_hours' => 24,

        // Zugang fuer die Conversions API (WP-32b).
        //
        // Ein eigener Schluessel, nicht der des Werbekontos: die Ereignisse
        // gehen an den Pixel, nicht an das Konto. Ohne ihn wird nichts
        // gesendet -- der Normalfall vor dem App Review.
        'capi_token' => env('META_CAPI_TOKEN'),

        // Erlaubte Conversions-API-Ereignisse. Abschliessend.
        'capi_allowed_events' => ['Lead', 'Schedule', 'Contact'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Werbung (WP-26)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/integrationen/meta.md, Abschnitte Werbekonten und
    | Rate Limits.
    |
    */

    'ads' => [

        // Berechtigungen der Login-Strecke. **Lesend, ausschliesslich.**
        // ads_management gehoert zu WP-27 und ist die Berechtigung, die im
        // App Review abgelehnt wird -- sie hier mitzufordern wuerde auch die
        // beiden anderen aufhalten.
        'scopes' => ['ads_read', 'business_management'],

        // Zeilen je Seite. Meta deckelt selbst, aber ohne eigene Angabe
        // liefert die API 25 und damit unnoetig viele Seiten.
        'page_size' => 100,

        // Harte Obergrenze an Seiten je Ebene. `paging.next` laeuft im
        // Zweifel im Kreis, und ein Abgleich, der nicht endet, haelt die
        // Warteschlange an.
        'max_pages' => 50,

        // Vorlauf der Ablaufwarnung fuer das Token. Ein langlebiges Token
        // gilt rund 60 Tage; wer erst am Ablauftag warnt, warnt zu spaet.
        'token_warning_days' => 14,

        // Nachlaufendes Fenster des Kennzahlenabgleichs (WP-28).
        //
        // **Gestern aendert sich noch.** Metas Zuordnungsfenster wirkt
        // rueckwirkend: die Zahlen eines Tages bewegen sich bis zu 28 Tage
        // lang. Wer nur den Vortag holt, friert falsche Werte ein -- und
        // niemand bemerkt es, weil die Zahl ja dasteht.
        'insights_window_days' => 28,

        // Zeitraeume, die die Oberflaeche anbietet.
        'ranges' => [7, 30, 90],

        /*
        | Kampagnenverwaltung (WP-27)
        |
        | Die Oberflaeche erzwingt diese Werte, statt auf Metas Ablehnung zu
        | warten. Fundstelle: docs/produkt.md, Das Differenzierungsmerkmal.
        */

        // **Keine Bewerbung aesthetischer Eingriffe an Minderjaehrige.**
        // Meta setzt fuer eingeschraenkte Kategorien eigene Auflagen; das
        // hier ist die Untergrenze des Produkts, nicht die Metas.
        'min_age' => 18,

        // Metas Obergrenze fuer die Altersangabe.
        'max_age' => 65,

        // Tagesbudget-Untergrenze in kleinster Einheit **je Waehrung**. Ein
        // fester Cent-Betrag waere fuer ein Konto in Franken falsch.
        // Unterhalb liefert Meta nicht aus, und das Geld liegt trotzdem fest.
        'min_daily_budget' => [
            'EUR' => 100,
            'CHF' => 100,
            'USD' => 100,
        ],

        // Erlaubte Kampagnenziele. Abschliessend: was hier fehlt, kann die
        // Oberflaeche nicht anbieten.
        'objectives' => [
            'OUTCOME_LEADS' => 'Anfragen sammeln',
            'OUTCOME_TRAFFIC' => 'Besuche auf der Buchungsseite',
            'OUTCOME_AWARENESS' => 'Bekanntheit in der Umgebung',
        ],

        // Umkreis in Kilometern.
        'radius_km' => ['min' => 1, 'max' => 50],

        // **Unter diesem Reifegrad des Brand Guide laeuft kein Vorschlag**
        // (WP-31). Aus "keine Angaben" entstuende eine Allerweltsanzeige, und
        // die klingt nach jeder anderen Praxis.
        //
        // **Das Tor gilt dem Modell, nicht der Praxis.** Wer seine Anzeige
        // selbst schreibt, denkt sich nichts aus und braucht keinen Brand
        // Guide.
        'min_reifegrad' => 60,

        // Metas Schaltflaeche auf der Anzeige. **Bewusst zurueckhaltend:**
        // "Jetzt buchen" verspricht einen Termin, den die Praxis erst
        // bestaetigen muss -- und ein Versprechen im Werbemittel ist genau
        // das, was das HWG nicht mag. "Mehr erfahren" fuehrt auf die
        // Buchungsseite, wo die Angaben stehen.
        'call_to_action' => 'LEARN_MORE',

        // Wie lange eine angeforderte Grafik als "entsteht gerade" gilt.
        //
        // **Ein abgestuerzter Lauf darf nicht ewig drehen.** Der Zustand ist
        // abgeleitet -- angefordert, keine Datei, kein Fehler --, und ohne
        // Grenze bliebe eine Kachel nach einem harten Abbruch fuer immer im
        // Ladezustand. Der Auftrag selbst wartet hoechstens fuenf Minuten
        // (services.kie.max_polls), hier ist Luft fuer die Warteschlange.
        'image_timeout_minutes' => 10,

        /*
        | Laengen eines Anzeigentextes (WP-31)
        |
        | Fundstelle: der Auftrag an das Sprachmodell in App\Anzeigen\
        | Textentwurf, der seit WP-31 mit 40 und 150 Zeichen arbeitet. Die
        | Oberflaeche haelt dieselben Grenzen ein -- ein von Hand
        | geschriebener Text soll nicht laenger sein duerfen als ein
        | erzeugter, sonst bricht er im Feed ab.
        */
        'text' => [
            'headline_max' => 40,
            'body_max' => 150,
            'description_max' => 150,

            // Spaltenbreite von ad_suggestions.cta.
            'cta_max' => 64,

            // Das eigene Bildmotiv. Kurz genug, dass es ein Motiv bleibt und
            // nicht zum zweiten Auftrag wird -- die Grenzen setzt das
            // Produkt, nicht die Eingabe.
            'brief_max' => 300,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Erscheinungsbild (WP-07)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: docs/design/farben.md, Abschnitt "Validierung bei der
    | Eingabe".
    |
    */

    'whitelabel' => [

        // **Unterhalb dieses Kontrasts zu Weiss wird abgelehnt.**
        //
        // Neongelb erreicht 1,07, Reinweiss 1,00 -- solche Werte ueberleben
        // die noetige Abdunklung nicht als das, was sie waren. Gold (#C9A227)
        // liegt darueber und wird abgedunkelt, nicht abgewiesen.
        //
        // farben.md: "Extreme Werte (Neongelb, Reinweiss) werden abgelehnt,
        // nicht stillschweigend korrigiert."
        'min_kontrast_eingabe' => 1.5,

        // Ab hier gilt eine Farbe als zu hell und wird abgedunkelt -- mit
        // Hinweis, nicht mit Ablehnung.
        'min_kontrast_ausgabe' => 4.5,

        // Farbtoene der Semantikfarben in Grad (HSL), mit Toleranz.
        // Liegt die Markenfarbe darin, erscheint eine Warnung: die Farbe wird
        // verwendet, Statusfarben bleiben unveraendert.
        'semantik_farbtoene' => [
            'Rot (Fehler)' => 0,
            'Bernstein (Warnung)' => 26,
            'Grün (Erfolg)' => 142,
            'Blau (Hinweis)' => 224,
        ],

        // In Grad. 25 statt 15, weil ein Farbton nicht erst bei der exakten
        // Semantikfarbe stoert: ein Praxisgruen bei 124 Grad liest sich
        // genauso als "gruen" wie das Erfolgsgruen bei 142. Petrol (178) und
        // Blau (224) bleiben mit 46 Grad Abstand ausserhalb -- die
        // Produktfarbe loest also keinen Fehlalarm aus.
        'semantik_toleranz' => 25,

        // Erlaubte Dateiarten und Groesse des Logos.
        //
        // **Kein SVG.** Eine SVG-Datei kann ein Skript enthalten, und
        // ausgeliefert von unserer eigenen Adresse waere das ein Skript auf
        // der oeffentlichen Buchungsseite -- gespeichertes XSS im eigenen
        // Ursprung. Ein Virenscanner findet so etwas nicht; die Beschraenkung
        // auf Rasterbilder schon.
        'logo_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'logo_max_mb' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | HWG-Pruefung (WP-30)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: specs/WP-30-hwg-compliance.md, docs/produkt.md
    |
    */

    'hwg' => [

        // **Die Bibliothek rechtssicherer Alternativformate.**
        //
        // docs/produkt.md: "die Pruefung sagt nicht nur, was nicht geht,
        // sondern was stattdessen geht". Ohne sie ist jeder Befund eine
        // Sackgasse.
        'alternativformate' => [
            [
                'titel' => 'Die Ärztin vorstellen',
                'beschreibung' => 'Wer behandelt, mit welcher Ausbildung, seit wann. '
                    .'Ein Gesicht nimmt mehr Unsicherheit als jede Ergebnisbeschreibung.',
            ],
            [
                'titel' => 'Den Ablauf erklären',
                'beschreibung' => 'Was passiert beim ersten Termin, wie lange dauert es, '
                    .'was ist danach zu beachten.',
            ],
            [
                'titel' => 'Die Räume zeigen',
                'beschreibung' => 'Empfang, Behandlungsraum, Wartebereich. Zulässig, '
                    .'und es beantwortet die Frage „wo lande ich da".',
            ],
            [
                'titel' => 'Preis- und Risikotransparenz',
                'beschreibung' => 'Was es kostet, was es nicht kann, welche Risiken es gibt. '
                    .'Das unterscheidet eine Praxis von einer Anzeige.',
            ],
        ],

        // Bis zu 50.000 Euro -- der Betrag gehoert in die Oberflaeche, nicht
        // in eine Fussnote (specs/WP-30, Fallstricke).
        'bussgeld_hinweis' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Marke (WP-29)
    |--------------------------------------------------------------------------
    |
    | Fundstelle: specs/WP-29-brand-guide.md
    |
    */

    'brand' => [

        // **Der Wortlaut der Erklaerung beim Hochladen von Referenzmaterial.**
        //
        // Er steht hier und nicht in der Oberflaeche, weil er mit jeder
        // Referenz mitgespeichert wird: wer spaeter fragt, was die Praxis
        // zugesichert hat, braucht den Text von damals. Eine Aenderung hier
        // gilt ab dann, nicht rueckwirkend.
        'declaration' => 'Ich bestätige, dass dieses Material keine Patientinnen oder '
            .'Patienten zeigt und keine Behandlungsergebnisse abbildet — weder vorher '
            .'noch nachher. Abgebildete Personen des Teams sind einverstanden.',

        // Erlaubte Dateiarten fuer Referenzmaterial.
        'reference_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

        // Groesse je Datei in Megabyte.
        'reference_max_mb' => 8,
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

        // Abonnements werden **deutlich** vor Ablauf erneuert, nicht kurz
        // davor: faellt der Job einmal aus, ist der Sync sonst tot.
        //
        // Je Anbieter, weil die Laufzeiten nicht vergleichbar sind. Ein
        // Google-Kanal haelt 30 Tage, ein Graph-Abonnement keine drei --
        // 24 Stunden Vorlauf waeren dort fast ein Drittel der Lebensdauer und
        // wuerden bei jedem Lauf erneuern.
        'renew_before_expiry_hours' => [
            'default' => 24,
            'microsoft' => 6,
        ],
    ],

];
