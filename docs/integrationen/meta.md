# Integration: Meta

Gilt für WP-00, WP-19 bis WP-22, WP-26 bis WP-28, WP-31, WP-32.

## Berechtigungen

| Berechtigung | Wofür |
|---|---|
| `ads_read` | Kampagnen und Kennzahlen lesen |
| `ads_management` | Kampagnen anlegen und ändern |
| `business_management` | Zugriff auf Werbekonten über Business-Manager-Partnerschaft |
| ~~`pages_messaging`~~ | Messenger — **zurückgestellt (P11)** |
| ~~`pages_manage_metadata`~~ | Webhook-Abonnements für Seiten — **zurückgestellt (P11)** |
| ~~`instagram_basic`~~ | Instagram-Geschäftskonto — **zurückgestellt (P11)** |
| ~~`instagram_manage_messages`~~ | Instagram-Direktnachrichten — **zurückgestellt (P11)** |
| `whatsapp_business_messaging` | WhatsApp senden und empfangen |
| `whatsapp_business_management` | Templates, Rufnummern, Konten verwalten |

**App Review ist der kritische Pfad des gesamten Projekts.** Meta verlangt eine funktionierende Demo je Berechtigung. Das erzeugt eine Henne-Ei-Situation: Du brauchst ein Stück Produkt, um die Berechtigung zu bekommen, die du fürs Produkt brauchst. Plane eine minimale Demo-Oberfläche ein, die nur der Review dient. Ablehnungen sind der Normalfall.

Voraussetzungen vor der Einreichung: Unternehmensverifizierung, Domain-Verifizierung, Datenschutzerklärung, Endpunkt zur Datenlöschung, Tech-Provider-Status.

### Woher die Berechtigungen kommen — zwei Wege, einer gilt

`META_LOGIN_CONFIG_ID` entscheidet, **welche Liste Meta beim Anmelden abfragt**:

| `META_LOGIN_CONFIG_ID` | Es gilt | Geändert wird |
|---|---|---|
| nicht gesetzt | `mrs.ads.scopes` aus `config/mrs.php` | im Repository |
| gesetzt | die Login-Konfiguration bei Meta | in der Meta-App |

**Ist sie gesetzt, schickt `Werbezugang::weiterleitung()` gar kein `scope` mehr
mit.** Die Tabelle oben steht dann nur noch auf dem Papier — maßgeblich ist
allein, was in der Konfiguration bei Meta hinterlegt ist.

Das ist leicht zu übersehen, weil sich nichts an der App ändert und nichts am
Code: eine einzige Umgebungsvariable verlegt die Quelle der Berechtigungen aus
dem Repository nach Meta.

### Die Art des Tokens entscheidet über den Weg

In derselben Konfiguration steht unter **Zugriffstoken**, welche Art Token Meta
ausstellt — und davon hängt ab, **wie** die Werbekonten zu finden sind:

| Zugriffstoken | Weg |
|---|---|
| Nutzer-Zugriffstoken | `me/adaccounts` |
| Systemnutzer-Zugriffstoken | `debug_token` → `granular_scopes` → Konto je Kennung |

Bei einem Systemnutzer-Token ist `me` der **Systemnutzer** — und den gibt es
als Graph-Objekt nicht. Meta antwortet zuerst mit **Code 200 („keine
Berechtigung")** und, fragt man eine andere Kante an `me`, mit **Code 100
(„Object with ID 'me' does not exist")**. Beides sieht nach einem
Rechteproblem aus und ist keines.

Der zweite Weg fragt deshalb nicht das Token, sondern **Meta über das Token**:
`debug_token` nennt zu jeder erteilten Freigabe die Objekte, für die sie gilt.
Bei `ads_management` und `ads_read` sind das genau die Werbekonten, die die
Praxis im Anmeldedialog ausgewählt hat. Gefragt wird dabei **als App**
(`app_id|app_secret`), nicht mit dem Zugang selbst — anders beantwortet Meta
die Frage nicht.

Genau das kostete am 24.09.2026 einen halben Tag: `ads_read`,
`ads_management`, `business_management` erteilt, Asset-Typ *Werbekonten*
angefragt, Anmeldung erfolgreich — und trotzdem „keine Berechtigung".

**Dem Token sieht man seine Art nicht an.** `Kontenauswahl::verfuegbare()`
geht deshalb erst den einen Weg und bei einer Abfuhr den anderen. Nur bei
einer Abfuhr: ein totes Token oder ein Rate Limit wird auf dem zweiten Weg
nicht besser, und ein zweiter Aufruf verdeckte den eigentlichen Grund.

### Ein Systemnutzer bekommt keine Berechtigung im Standardzugriff

**Die Login-Konfiguration sagt, was *angefragt* wird — nicht, was *erteilt*
wird.** Meta lässt still weg, was es nicht vergeben darf, und stellt ein
gültiges Token über den Rest aus. Fehlermeldung: keine.

Der Hinweis steht in Metas eigener Oberfläche über der Berechtigungsliste:

> Berechtigungen im Standardzugriff werden nur von **Personen mit Rollen in
> dieser App** angefordert.

Ein **Systemnutzer hat keine Rolle in der App** — er lebt im
Business-Portfolio. Solange `ads_read` und `ads_management` nur Standardzugriff
haben, bekommt ein Systemnutzer-Token sie nie, egal was in der Konfiguration
steht.

Am 24.09.2026 sah das so aus: angefragt waren `ads_management`, `ads_read`,
`business_management`, `pages_show_list`, `pages_read_engagement`; erteilt
wurden `pages_show_list`, `pages_read_engagement`, `public_profile`. Genau die
drei Werberechte fehlten — die drei, die noch keinen erweiterten Zugriff haben.

| Wann | Was geht |
|---|---|
| ohne erweiterten Zugriff | nur Nutzertoken, und nur für Personen mit Rolle in der App |
| mit erweitertem Zugriff | auch Systemnutzer-Token, also der Weg für den Betrieb (B1) |

**Damit ist App Review keine Formalität am Ende, sondern die Voraussetzung für
die Token-Art, die im Betrieb überhaupt tragfähig ist.**



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

Umgesetzt in WP-27, im Server und nicht in der Seite (`config/mrs.php` → `ads`): Mindestalter 18 ohne Ausnahme, Mindestbudget je Währung, eine abschließende Liste erlaubter Ziele — und **kein Feld für Interessen**. „Botox" als Interesse auszuwählen wäre eine Behandlungsbezeichnung Richtung Meta (Regel 2), in einem Feld, an das niemand denkt. Zielgruppe ist Umkreis, Alter, Geschlecht; Custom Audiences sind ohnehin aus (C8).

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

*Nachtrag 26.09.2026:* Ob die Berechnung im Fenster wirklich kommt, ist offen — B12 ging vom Gegenteil aus. **Das Produkt ist auf beides vorbereitet (B14):** Antworten im Fenster werden gezählt, getrennt von den Templates (die gegen das Kontingent laufen), und mit `WHATSAPP_SERVICEFENSTER_CENT` bepreist, vorerst 0. Der Preis steht an jeder Nachricht (`messages.charge_tenth_cents`), sobald die Statusrückmeldung die Kategorie `service` meldet, und geht am Monatsersten als ein Sammelposten zu Stripe. Gesperrt wird eine Antwort nie.

Folge: WhatsApp darf im Abo nicht unbegrenzt sein. Die Kategorie wird je Nachricht **aus der API-Antwort** übernommen, nie geschätzt.

**Und zwar aus der Statusrückmeldung, nicht aus der Sendeantwort** (gefunden in WP-20a). Die Antwort auf den Versand trägt `messages[0].id` und sonst nichts; `pricing.category` kommt Minuten später als eigenes Webhook-Ereignis. Eine frisch gesendete Nachricht hat deshalb **keine** Kategorie — nicht `none`. `none` wäre die Schätzung mit der Aussage „kostenlos", und die fällt nie auf.

**Service-Fenster.** 24 Stunden ab der letzten eingehenden Nachricht. Danach ist nur ein genehmigtes Template möglich. `conversations.service_window_expires_at` bildet das ab, die Inbox zeigt sichtbar an, wenn ein kostenpflichtiges Template nötig wird, inklusive Kosten.

**Templates.** Genehmigung durch Meta, je Sprache getrennt — derselbe Name kann auf Deutsch stehen und auf Englisch abgelehnt sein. Gelesen werden sie unter der **WABA-Kennung**, gesendet wird unter der **Rufnummern-ID**: zwei Kennungen, eine Verbindung. Die Einordnung als `utility` statt `marketing` senkt die Kosten deutlich und hängt allein von der Formulierung ab. Das ist ein Versuch-und-Irrtum-Vorgang bei der Einreichung, entsprechend Zeit einplanen.

**Opt-in** ist nachweisbar erforderlich. `consents` mit `channel_identity_id`, `text_snapshot` und Zeitpunkt. Es gilt für das **Template außerhalb** des Fensters: wer uns schreibt, hat sich damit gemeldet, und die Antwort im Fenster braucht keinen weiteren Nachweis.

**Qualitätsbewertung.** Meta bewertet Rufnummern nach Nutzerreaktionen. Zu viele Blockierungen senken das Versandlimit. Die Frequenzbremse bei Wartelistenangeboten schützt auch davor.

**Einrichtung** (seit 26.09.2026 im Produkt, *Einstellungen → WhatsApp*). Eingetragen werden WABA-ID, Rufnummern-ID und das Token eines Systembenutzers mit `whatsapp_business_messaging` und `whatsapp_business_management`. Gespeichert wird sofort, geprüft in der Warteschlange (`WhatsAppPruefen`, B2): Rufnummer lesen, **`POST /{waba}/subscribed_apps`** — ohne dieses Abonnement kommt keine einzige Nachricht an —, Templates abgleichen. Eine WABA gehört genau einer Praxis; die Zustellung findet ihre Praxis über diese Kennung.

**Medien.** Die Zustellung trägt nur eine Kennung. Die Datei kommt in zwei Schritten, beide mit Token: `GET /{media-id}` liefert eine kurzlebige Adresse, erst die liefert die Datei (`MedienHolen`, `WhatsAppMedien`). **Das Token geht nur an Meta** — die zweite Adresse wird gegen `mrs.channels.whatsapp.media_hosts` geprüft, bevor es mitgeht. Über 10 MB wird nicht geholt. Abgelegt wird über den Anhangspeicher: Virenprüfung, Pflicht-Ablaufdatum (C6), angezeigt erst nach der Prüfung (C12).

## Instagram und Messenger — zurückgestellt

**Nicht Teil des Produkts** (Entscheidung P11, 16.09.2026). Die
Berechtigungen `pages_messaging`, `pages_manage_metadata`, `instagram_basic`
und `instagram_manage_messages` gehören damit **nicht** in die App Review —
jede eingereichte Berechtigung verlangt eine eigene funktionierende Demo und
kann einzeln abgelehnt werden.

Was unten steht, gilt weiterhin für den Tag, an dem die beiden nachgezogen
werden. Bis dahin ist es Hintergrund, kein Auftrag.


- **Scoped IDs:** Nutzerkennungen sind je Seite unterschiedlich. Dieselbe Person kann mehrere Identitäten haben. `channel_identities` bildet das ab, Zusammenführung nur bei sicherem Signal.
- **24-Stunden-Fenster** wie bei WhatsApp, mit abweichenden Ausnahmen.
- **Human Agent Tag** erlaubt eine verlängerte Antwortfrist bei menschlicher Bearbeitung. Nur nutzen, wenn tatsächlich ein Mensch antwortet.
- **Story-Antworten und Reaktionen** kommen als eigene Ereignistypen und müssen behandelt werden, sonst erscheinen sie als leere Nachrichten.

## Datenschutz

**Keine Gesundheitsdaten an Meta.** Regel 2 aus `CLAUDE.md`, ausführbar abgesichert durch den Test aus `docs/fachlogik/attribution.md`. Gilt für die Conversions API, für Pixel-Parameter, für Ereignisnamen und für Kampagnen-, Anzeigengruppen- und Anzeigennamen.

**Der Anzeigeninhalt ist ausgenommen** (Entscheidung C9). Eine Praxis, die für eine Behandlung wirbt, benennt sie in Überschrift und Text — das ist eine Aussage über ein Angebot, nicht über eine Patientin. Die Grenze läuft zwischen Angebot und Person, nicht am Wort.

**Kampagnennamen bleiben trotzdem neutral**, und der Grund liegt im eigenen Haus: sie sind Werbe-Metadaten, liegen unverschlüsselt und frieren beim Termin als `attribution_snapshot` ein (D13). „Botox Herbst" als Kampagnenname setzt einen Behandlungsnamen in ein offenes Feld unmittelbar neben einen Kontakt. Die Kampagnenbenennung erzeugt deshalb **das Produkt** aus Zeitraum, Ziel und Standort, nicht die freie Eingabe des Kunden (WP-27).

Keine Custom Audiences aus Kontaktlisten (Entscheidung C8).

## Das Pixel auf der Buchungsseite

**Das Produkt baut es ein, nicht der Kunde.** Die Praxis hinterlegt unter
*Einstellungen → Tracking* ausschließlich die Pixel-ID — eine Ziffernfolge,
geprüft gegen `^[0-9]{6,20}$`. Ein Feld für einen ganzen Skriptschnipsel wäre
die Hintertür, die Regel 2 gerade schließt: darin ließe sich `content_name`
mit dem Behandlungsnamen senden, und niemand würde es bemerken.

Gesendet wird genau zweierlei, jeweils **ohne einen einzigen Parameter**:

| Ereignis   | Wann                                  |
| ---------- | ------------------------------------- |
| `PageView` | Aufruf der Buchungsseite              |
| `Lead`     | Bestätigungsseite nach der Buchung    |

`autoConfig` ist **aus**, gesetzt **vor** `init`. Mit ihm entscheidet Meta
selbst, was mitgeht: Seitentitel, Beschriftungen angeklickter Schaltflächen,
Formularfelder. Auf dieser Seite stehen Behandlungsnamen — genau die Daten,
die Regel 2 verbietet.

Abgesichert durch `tests/Feature/Meta/PixelTest.php`: der Test liest den
Quelltext des Buchungslayouts, lässt nur `PageView` und `Lead` ohne zweites
Argument zu, prüft die Reihenfolge von `autoConfig` und `init` und hält jeden
aktiven Katalognamen gegen den Pixelabschnitt.
