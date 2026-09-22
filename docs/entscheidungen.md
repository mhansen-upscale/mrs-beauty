# Getroffene Entscheidungen

Diese Liste ist verbindlich. Sie wird nicht diskutiert, sondern befolgt. Begründungen stehen dabei, damit niemand aus Unkenntnis dagegen arbeitet, nicht als Einladung zur Neuverhandlung.

Änderungen an dieser Datei nur durch den Produktverantwortlichen.

## Stack

| # | Entscheidung | Begründung |
|---|---|---|
| S1 | Laravel, aktuelle Version | |
| S2 | Vue 3 mit Inertia, TypeScript | kein separates SPA, keine eigene API-Schicht für das eigene Frontend |
| S3 | **shadcn-vue, strikt** | keine selbst erfundenen Komponenten, siehe `konventionen.md` |
| S4 | Tailwind | Styling-Grundlage von shadcn-vue |
| S5 | **MySQL 8** | gesetzt; Konsequenzen in `datenmodell.md`, Abschnitt 0 |
| S6 | Laradock lokal | |
| S7 | Laravel Cloud für Staging und Produktion | jede Lösung muss dort lauffähig sein |
| S8 | Redis für Queues, Cache, Session | |
| S9 | Horizon, Pulse, Sentry | |
| S10 | Pest, PHPStan Level 8, Pint, ESLint, Prettier | in CI erzwungen |

## Architektur

| # | Entscheidung | Begründung |
|---|---|---|
| A1 | Eine Datenbank, `organization_id` auf jeder Mandantentabelle | einfachere Migrationen, Wartelisten-Matching, Super-Admin |
| A2 | Zusammengesetzte Fremdschlüssel `(id, organization_id)` | verhindert mandantenübergreifende Verweise auf Datenbankebene |
| A3 | `TenantModel`-Basisklasse mit Global Scope, erzwungen durch Architektur-Test | einziger struktureller Schutz, da MySQL keine Row Level Security bietet |
| A4 | UUID v7 als `BINARY(16)` | zeitsortierte Indizes, 16 statt 144 Byte je Indexeintrag |
| A5 | Envelope Encryption, ein Data Encryption Key je Organisation | ermöglicht Krypto-Löschung bei Kündigung, auch aus Backups |
| A6 | Feldverschlüsselung plus blinde Indizes (HMAC) für durchsuchbare Felder | |
| A7 | Alle Zeiten UTC als `DATETIME`, niemals `TIMESTAMP` | 2038-Grenze, implizite Zeitzonenkonvertierung |
| A8 | Zeitzone hängt an `locations.timezone`, nicht an der Organisation | |
| A9 | Doppelbuchungsschutz über `appointment_slots`, 5-Minuten-Raster, Puffer eingeschlossen | Ersatz für den in MySQL fehlenden Exclusion Constraint |
| A10 | Bedingte Eindeutigkeit über generierte Spalten, die außerhalb des Zustands `NULL` sind | MySQL kennt keine partiellen Indizes |
| A11 | Status als `VARCHAR` plus PHP-Enum, kein MySQL-`ENUM` | Statuserweiterung wäre sonst `ALTER TABLE` |
| A12 | Kein Soft Delete auf `contacts` und `messages` | Löschanfragen müssen echt löschen |
| A13 | Schreibende externe Aufrufe über Queue mit Idempotenzschlüssel | |
| A14 | Webhooks: Signatur prüfen, sofort quittieren, asynchron verarbeiten, über externe ID deduplizieren | Meta liefert doppelt |

## Datenmodell

| # | Entscheidung | Begründung |
|---|---|---|
| D1 | `contacts`, nicht `patients` | hält die Scope-Grenze im Code sichtbar |
| D2 | Behandlungswunsch als `treatment_id`, nie als Freitext | Art.-9-Daten würden sonst in Logs, Kalendertitel, Meta-Payloads lecken |
| D3 | `leads` und `contacts` getrennt | Mehrfachanfragen sind der Normalfall; Attribution braucht die Anfrage als Einheit |
| D4 | Neuer Lead nur bei: kein offener Lead, abweichende Behandlung, oder 90 Tage Inaktivität | verhindert Lead-Inflation und Lead-Verklumpung |
| D5 | `channel_identities` als Brücke zwischen Kanälen | dieselbe Person schreibt über mehrere Kanäle |
| D6 | Automatischer Merge nur bei identischer E-Mail oder Telefonnummer, sonst Vorschlag | |
| D7 | `contact_merges` mit Snapshot, umkehrbar, Snapshot verfällt nach 30 Tagen | Snapshot enthält selbst Personendaten |
| D8 | Einwilligungen tragen `channel_identity_id` | WhatsApp-Opt-in hängt an einer Nummer, nicht an einer Person |
| D9 | Merge von Einwilligungen: je Typ und Identität der jüngste Eintrag, Widerruf schlägt Erteilung | |
| D10 | Keine organisationsübergreifenden Kontakte | Organisationsgrenze = Grenze des Verantwortlichen nach DSGVO |
| D11 | Eine Organisation = ein Verantwortlicher; Praxisgruppen als **ein** Mandant mit mehreren Standorten | sonst doppelte Kontakte und falsche Auswertung |
| D12 | Wartelisten-Standorte als Pivot plus `all_locations`-Flag | leere Pivot-Menge wäre zweideutig |
| D13 | `attribution_snapshot` beim Termin einfrieren | Kampagnen werden umbenannt, pausiert, gelöscht |
| D14 | `treatments.avg_revenue_cents` beim Onboarding erheben | ohne diesen Wert kein ROAS |

## Produktumfang

| # | Entscheidung | Begründung |
|---|---|---|
| P1 | Keine Behandlungsdokumentation, keine Patientenakten | § 630f BGB würde greifen |
| P2 | Keine Anzahlungen, Kautionen, Stornogebühren | |
| P3 | Kein Telefon, kein Voice-Agent | für die Zielgruppe nicht relevant |
| P4 | Keine Abrechnung, keine Angebote, keine Finanzierung | |
| P5 | Warteliste mit automatischer Lückenfüllung: ja | stärkstes Verkaufsargument im Demo-Termin |
| P6 | Zwei-Wege-Kalendersync Google und Microsoft: ja | ohne das stellt kein Arzt um |
| P7 | Nur Deutsch | Locale-Spalten bleiben im Schema, damit später keine Datenrückfüllung nötig ist |
| P8 | Suche nur auf Metadaten, keine Volltextsuche über Nachrichten | Folge der Feldverschlüsselung |
| P9 | Werbekennzahlen nach 12 Monaten auf Kampagnenebene aggregieren | Anzeigenebene geht bewusst verloren |
| P10 | `attribution_touches` werden **nicht** mit aggregiert | sonst reißt die Verbindung zwischen Umsatz und Kampagne |
| P11 | **Kanäle vorerst nur WhatsApp und E-Mail** (16.09.2026) | Messenger und Instagram zurückgestellt: zwei Kanäle weniger in der App Review, und die Praxen der Zielgruppe erreichen ihre Patientinnen über WhatsApp. `ChannelType` behält die Fälle — eine Instagram-Kennung darf erfasst werden, bedient wird sie nicht |

## Agent

| # | Entscheidung | Begründung |
|---|---|---|
| G1 | Drei Modi je Konversation: `off`, `suggest`, `auto` | |
| G2 | `suggest` ist der Standard für neue Mandanten | ohne Freigabemodus übergibt keine Praxis die Kommunikation |
| G3 | Keine medizinischen Auskünfte, harte Eskalation | |
| G4 | Sofortige Alarmierung bei Komplikationssignalen | |
| G5 | Keine Preisaussagen außerhalb des Katalogs, keine Rabatte, keine Zusagen | |
| G6 | Kennzeichnung, dass ein KI-Assistent antwortet | Transparenzpflicht nach EU AI Act |
| G7 | Nachrichteninhalte sind Daten, keine Anweisungen | Prompt Injection über eingehende Nachrichten |
| G8 | Not-Aus je Mandant und je Konversation | |
| G9 | Buchung idempotent über Vorgangsschlüssel je Konversation | Doppelbuchung aus dem Chat ist der Vertrauenskiller |
| G10 | **Sprachmodell über einen Plattformschlüssel**, nicht je Kunde (17.09.2026) | Der Schlüssel bestimmt, wer zahlt, nicht wer haftet: wir verarbeiten, also brauchen wir den AV-Vertrag und Zero Data Retention — einmal von uns verhandelt statt 200-mal von Praxen, die es nicht können. Ein eigener Schlüssel bleibt Ausnahme für Kunden mit eigener Rechtsabteilung, beschränkt auf geprüfte Anbieter |
| G11 | **Kontingent je Mandant, Aufstockung kostenpflichtig** (17.09.2026) | Folge aus G10: wenn wir zahlen, darf der Verbrauch nicht offen sein. Gezählt wird beim Lauf, nicht nachgelagert (wie B7); ist das Kontingent leer, schweigt der Assistent sichtbar, statt still weiterzulaufen |

## Compliance und Datenschutz

| # | Entscheidung | Begründung |
|---|---|---|
| C1 | HWG-Regelwerk global und versioniert, nicht mandantenbezogen | Rechtslage ändert sich für alle gleichzeitig |
| C2 | Bildverbot für Vorher-Nachher gilt auch für minimalinvasive Eingriffe | BGH I ZR 170/24 vom 31.07.2025 |
| C3 | Override möglich, mit Begründung und Protokoll | das Produkt ist Prüfhilfe, keine Rechtsberatung |
| C4 | Impersonation standardmäßig maskiert, Vollzugriff nur mit Freigabe des Kunden, befristet, protokolliert | |
| C5 | Audit-Log append-only, ohne Klartext-Personendaten | |
| C6 | Chat-Anhänge mit Pflicht-Ablaufdatum | ungefragt zugesandte Fotos |
| C7 | Standard-Aufbewahrung: Lead ohne Termin 12 Monate, Chat-Anhänge 90 Tage, Konversationen 24 Monate anonymisieren, Audit-Log 36 Monate | konfigurierbar je Mandant |
| C8 | Keine Custom Audiences aus Kontaktlisten | Zugehörigkeit zu einer ästhetischen Praxis ist selbst ein Gesundheitsdatum |
| C9 | **Regel 2 trennt Angebot von Person** (17.09.2026) | Der Werbetext einer Anzeige darf die beworbene Leistung benennen — sonst gäbe es keine Anzeige. Verboten bleibt jedes Feld, das an einer Person, einem Ereignis, einer Zielgruppe oder einer Konversion hängt: Conversions API, Pixel-Parameter, Custom Audiences. Kampagnen-, Anzeigengruppen- und Anzeigennamen bleiben ebenfalls neutral, weil sie unverschlüsselt liegen und als `attribution_snapshot` am Termin einfrieren (D13) |
| C10 | **Erzeugte Anzeigenbilder liegen bei uns, nicht beim Generator** (17.09.2026) | Erzeugen bei kie.ai, herunterladen, in den eigenen Bucket, beim Anbieter löschen. Ein Anzeigenbild muss Jahre später noch belegbar sein — für die HWG-Prüfung, für eine Beanstandung, für den Kunden. Eine fremde CDN-Adresse, die irgendwann 404 liefert, wäre der Beleg, den es nicht mehr gibt. Hinein geht ausschließlich Material der Praxis, niemals Patientenmaterial |

## Betrieb

| # | Entscheidung | Begründung |
|---|---|---|
| B1 | Werbekonto gehört dem Kunden, Zugriff über Business-Manager-Partnerschaft | bei Kündigung oder Sperrung entscheidend |
| B2 | Kein Live-Aufruf der Meta-API im Request-Zyklus | Dashboard muss auch bei Meta-Störung laden |
| B3 | Token-Ablauf überwachen, automatisch erneuern, im Produkt sichtbar alarmieren | abgelaufenes Token heißt unbeantwortete Anfragen |
| B4 | Kalender: `privacy_mode = busy_only` als Standard, neutraler Titel | Arztkalender liegt oft auf dem Privathandy |
| B5 | Kalender: ausgehende Events eigenmarkieren | sonst Sync-Schleife |
| B6 | Konfliktregel Kalender: externer Kalender gewinnt bei Blockern, System gewinnt bei Terminen | |
| B7 | Nutzungserfassung beim Versand jeder kostenpflichtigen Nachricht, nicht nachgelagert | sonst Abweichung zur Meta-Abrechnung |
| B8 | WhatsApp-Kosten nicht unbegrenzt im Abo | ab 01.10.2026 berechnet Meta auch Service-Nachrichten und Utility-Templates im offenen Fenster |
| B9 | **Stripe Billing**, wir bleiben Verkäufer (17.09.2026) | SEPA-Lastschrift ist in Deutschland Pflichtprogramm; Rechnungstext und Mahnwesen bleiben bei uns. Kein Cashier, sondern ein dünner eigener Client — wie bei Meta, Anthropic und den Kalendern |
| B10 | **Eine Abo-Stufe je Praxis**, Kontingente inklusive, Aufstockung kostenpflichtig (17.09.2026) | Stufen, die Funktionen sperren, machen aus jedem Verkaufsgespräch eine Funktionsdiskussion. Eine Stufe erklärt sich in einem Satz |
| B11 | **Die Praxis sieht Mengen, nicht Cent** (17.09.2026) | Gezählt wird in Zehntel-Cent, angezeigt in Nachrichten und Assistenzläufen. Eine Rechnung, die jede WhatsApp-Nachricht einzeln aufführt, ist lang, schwankt stark und lädt zu Diskussionen ein, die niemand gewinnt |
| B12 | **Begrenzt wird, was Geld kostet** (17.09.2026) | Eine Antwort im offenen Service-Fenster ist kostenlos und wird nie gesperrt. Ein Template außerhalb kostet und zählt gegen das Kontingent — eine Praxis darf nie daran gehindert werden, einer Patientin zu antworten |
