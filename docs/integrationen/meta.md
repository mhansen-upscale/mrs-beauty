# Integration: Meta

Gilt für WP-00, WP-19 bis WP-22, WP-26 bis WP-28, WP-31, WP-32.

## Berechtigungen

| Berechtigung | Wofür |
|---|---|
| `ads_read` | Kampagnen und Kennzahlen lesen |
| `ads_management` | Kampagnen anlegen und ändern |
| `business_management` | Zugriff auf Werbekonten über Business-Manager-Partnerschaft |
| `pages_messaging` | Messenger empfangen und senden |
| `pages_manage_metadata` | Webhook-Abonnements für Seiten |
| `instagram_basic` | Instagram-Geschäftskonto lesen |
| `instagram_manage_messages` | Instagram-Direktnachrichten |
| `whatsapp_business_messaging` | WhatsApp senden und empfangen |
| `whatsapp_business_management` | Templates, Rufnummern, Konten verwalten |

**App Review ist der kritische Pfad des gesamten Projekts.** Meta verlangt eine funktionierende Demo je Berechtigung. Das erzeugt eine Henne-Ei-Situation: Du brauchst ein Stück Produkt, um die Berechtigung zu bekommen, die du fürs Produkt brauchst. Plane eine minimale Demo-Oberfläche ein, die nur der Review dient. Ablehnungen sind der Normalfall.

Voraussetzungen vor der Einreichung: Unternehmensverifizierung, Domain-Verifizierung, Datenschutzerklärung, Endpunkt zur Datenlöschung, Tech-Provider-Status.

## Werbekonten

**Das Werbekonto gehört dem Kunden.** Zugriff erfolgt über eine Partnerschaft im Business Manager, nicht über eine Übertragung. Bei Kündigung oder Sperrung ist das der Unterschied zwischen einem Ärgernis und einem Rechtsstreit.

Zugriff über **Systembenutzer-Token** je Mandant, verschlüsselt gespeichert, mit Ablaufüberwachung. Kein Nutzertoken, weil das mit dem Ausscheiden eines Mitarbeiters ungültig wird.

## API-Version

Die Version wird in der Konfiguration festgenagelt, nicht aus dem SDK übernommen. Meta setzt Versionen nach etwa zwei Jahren ab und kündigt das an. Ein Kalendereintrag zur halbjährlichen Prüfung gehört zum Betrieb.

## Rate Limits und Fehler

Alle schreibenden Aufrufe laufen über Queues mit Idempotenzschlüssel, niemals im Request-Zyklus. Das Dashboard muss auch bei einer Meta-Störung laden, nur mit veralteten Zahlen und sichtbarem Hinweis.

Zu behandelnde Fehlerklassen:

| Klasse | Reaktion |
|---|---|
| Rate Limit | exponentielles Zurückweichen, Wiederholung |
| Token ungültig oder abgelaufen | Verbindung auf `expired`, Hinweis im Produkt, keine Wiederholung |
| Berechtigung fehlt | Verbindung auf `degraded`, Hinweis, keine Wiederholung |
| Werbekonto gesperrt | Hinweis im Produkt, alle Schreibvorgänge anhalten |
| Vorübergehender Fehler | Wiederholung |
| Fachlicher Fehler (z. B. abgelehnte Anzeige) | dem Nutzer im Klartext anzeigen |

## Werbung für ästhetische Eingriffe

Meta behandelt kosmetische Verfahren als eingeschränkte Kategorie. Zu erwarten sind Auflagen bei Alters-Targeting und Standort sowie Ablehnungen bei Vorher-Nachher-Darstellungen.

**Die Oberfläche muss diese Anforderungen erzwingen, nicht erst die API-Ablehnung anzeigen.** Ein Kunde, der eine Kampagne baut und beim Speichern eine Meta-Fehlermeldung bekommt, hält das Produkt für kaputt.

Unabhängig davon gilt deutsches Recht strenger: Der BGH hat Vorher-Nachher-Bilder mit Urteil vom 31.07.2025 (I ZR 170/24) auch für minimalinvasive Eingriffe verboten. Die HWG-Prüfung aus WP-30 läuft **vor** jeder Übermittlung an Meta.

## Webhooks

Ein Endpunkt für alle Meta-Kanäle. Ablauf:

1. Signatur über `X-Hub-Signature-256` prüfen, bei Fehlschlag verwerfen
2. Sofort mit 200 quittieren, ohne Verarbeitung
3. Rohereignis speichern, Job auf Queue `realtime`
4. Dedupliziert über die externe Nachrichten-ID

**Meta liefert Webhooks doppelt.** Das ist kein Randfall, sondern Normalbetrieb. Ohne Deduplizierung antwortet der Agent zweimal auf dieselbe Nachricht.

Rohereignisse werden 14 Tage aufbewahrt, damit fehlgeschlagene Verarbeitungen erneut eingespielt werden können.

## WhatsApp

**Kostenmodell.** Ab 01.10.2026 berechnet Meta auch Service-Nachrichten und Utility-Templates innerhalb des 24-Stunden-Fensters, die bis dahin kostenlos waren. Marketing-Templates nach Deutschland liegen ohne Volumenrabatt bei über 0,12 USD je Nachricht. Nachrichten des Meta Business Agent werden seit 01.08.2026 nach Token abgerechnet.

Folge: WhatsApp darf im Abo nicht unbegrenzt sein. Die Kategorie wird je Nachricht **aus der API-Antwort** übernommen, nie geschätzt.

**Service-Fenster.** 24 Stunden ab der letzten eingehenden Nachricht. Danach ist nur ein genehmigtes Template möglich. `conversations.service_window_expires_at` bildet das ab, die Inbox zeigt sichtbar an, wenn ein kostenpflichtiges Template nötig wird, inklusive Kosten.

**Templates.** Genehmigung durch Meta, je Sprache getrennt. Die Einordnung als `utility` statt `marketing` senkt die Kosten deutlich und hängt allein von der Formulierung ab. Das ist ein Versuch-und-Irrtum-Vorgang bei der Einreichung, entsprechend Zeit einplanen.

**Opt-in** ist nachweisbar erforderlich. `consents` mit `channel_identity_id`, `text_snapshot` und Zeitpunkt.

**Qualitätsbewertung.** Meta bewertet Rufnummern nach Nutzerreaktionen. Zu viele Blockierungen senken das Versandlimit. Die Frequenzbremse bei Wartelistenangeboten schützt auch davor.

## Instagram und Messenger

- **Scoped IDs:** Nutzerkennungen sind je Seite unterschiedlich. Dieselbe Person kann mehrere Identitäten haben. `channel_identities` bildet das ab, Zusammenführung nur bei sicherem Signal.
- **24-Stunden-Fenster** wie bei WhatsApp, mit abweichenden Ausnahmen.
- **Human Agent Tag** erlaubt eine verlängerte Antwortfrist bei menschlicher Bearbeitung. Nur nutzen, wenn tatsächlich ein Mensch antwortet.
- **Story-Antworten und Reaktionen** kommen als eigene Ereignistypen und müssen behandelt werden, sonst erscheinen sie als leere Nachrichten.

## Datenschutz

**Keine Gesundheitsdaten an Meta.** Regel 2 aus `CLAUDE.md`, ausführbar abgesichert durch den Test aus `docs/fachlogik/attribution.md`. Gilt für Conversions API, Kampagnennamen, Anzeigentexte in der Verwaltung und jede andere Übermittlung.

Keine Custom Audiences aus Kontaktlisten (Entscheidung C8).
