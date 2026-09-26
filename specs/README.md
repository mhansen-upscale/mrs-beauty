# Task-Briefings

Ein Briefing je Arbeitspaket. **Eine Session, ein Paket.**

## Aufbau jeder Datei

- **Ziel** — ein Satz
- **Vorher lesen** — nur diese Dokumente in den Kontext nehmen
- **Voraussetzungen** — welche Pakete abgeschlossen sein müssen
- **Schritte** — Reihenfolge der Umsetzung
- **Abnahmekriterien** — sind die Testfälle, werden vor der Implementierung geschrieben
- **Nicht in diesem Paket** — ausdrückliche Abgrenzung
- **Fallstricke** — Punkte, an denen es erfahrungsgemäß schiefgeht

## Fundament

| | Paket | Hinweis |
|---|---|---|
| WP-02 | Projekt-Setup | steht |
| WP-03 | Mandantenfähigkeit & Verschlüsselung | nicht nachrüstbar; steht |
| WP-04 | Benutzer, Rollen, Einladungen | steht; Rollenkatalog abgeleitet, zu bestätigen |
| WP-05 | Audit-Log & Impersonation | steht |
| WP-06 | Abo & Abrechnung | steht: Stripe Billing, eine Stufe + Nutzung, Kontingent + Aufstockung; **Preise festgelegt** (B15), Antworten im Service-Fenster gezählt und bepreist (B14) |
| WP-07 | Whitelabel | steht: Markenfarbe mit Prüfung, Logo, Impressum und Datenschutz auf der Buchungsseite |

WP-05 bis WP-07 können nach WP-11 nachgezogen werden, falls früh etwas Vorzeigbares gebraucht wird. WP-03 und WP-04 nicht: Mandantentrennung und Verschlüsselung nachträglich einzuziehen bedeutet vollständige Neuverschlüsselung aller Daten.

## M1 · Buchung

| | Paket | Hinweis |
|---|---|---|
| WP-08 | Praxisstammdaten | steht |
| WP-09 | Leistungskatalog & Terminarten | steht; Beschreibung und Preis mit HWG-Ampel (C11) |
| WP-10 | Verfügbarkeits-Engine | **größter Risikoposten**, Spezifikation zwingend lesen; steht, alle 22 Testfälle |
| WP-11 | Terminverwaltung intern | steht; **Wochenansicht als Zeitraster** seit 26.09.2026 |
| WP-12 | Öffentliche Buchungsseite | steht; Preis und Beschreibung nach HWG-Prüfung (C11) |
| WP-13 | Erinnerungen & Bestätigungen | steht; Kopf und Fuß der Mail tragen die Praxis |
| WP-14 | Kalendersync Google | steht, gegen echte Zugangsdaten gelaufen |
| WP-15 | Kalendersync Microsoft | steht samt Abstraktion; **Azure-App-Registrierung offen** (`docs/integrationen/kalender.md`) |

## M2 · Inbox — erster verkaufsfähiger Stand

| | Paket | Hinweis |
|---|---|---|
| WP-16 | Kontakte & Kanalidentitäten | steht |
| WP-17 | Leads & Pipeline | steht; die Antwort im Posteingang vermerkt die Reaktion selbst |
| WP-18 | Notizen, Anhänge, Einwilligungen, Aufbewahrung | steht; Anhänge werden ausgeliefert (C12) |
| WP-19 | Kanal-Infrastruktur | steht |
| WP-20 | Kanäle (WhatsApp, E-Mail) | steht; Messenger und Instagram zurückgestellt (P11); WhatsApp-Medien und Einrichtung im Produkt seit 26.09.2026 |
| WP-21 | Inbox-Oberfläche | steht |
| WP-22 | Agent: Klassifikation & Vorschlag | steht |
| WP-23 | Agent: Guardrails & Eskalation | eigenes Paket, nicht verkürzen; steht |
| WP-24 | Agent: Automatische Buchung | steht; dazu Warteliste statt Sackgasse (G13), Behandlerwunsch, Absagen und Verschieben (G12) |
| WP-25 | Warteliste & Lückenfüllung | steht; wackelige Termine mit Klärung im Produkt, Kosten je Angebot |

## M3 · Wachstum

| | Paket | Hinweis |
|---|---|---|
| WP-26 | Ad-Account-Anbindung & Sync | steht: Werbekonto verbinden, Struktur lesend spiegeln, **schreibt bei Meta nichts** — Login-Konfiguration und App Review offen |
| WP-27 | Kampagnenverwaltung | steht: anlegen, pausieren, Budget — schreibend, an **einer** Stelle; braucht `ads_management` |
| WP-27b | Anzeigen schalten | steht: aus freigegebenem Entwurf mit Grafik wird Creative und Anzeige; Kampagne bearbeiten (Budget, Zielgruppe); braucht `ads_management` und eine Facebook-Seite |
| WP-28 | Insights & Aggregation | steht: Tageszahlen je Ebene, Summen und Quoten gerechnet, P9 über die Aufbewahrung |
| WP-29 | Brand Guide | steht: Tonalität, Wortwahl, Referenzmaterial mit Erklärung im Wortlaut; liefert `banned_terms` an WP-30 |
| WP-30 | HWG-Compliance-Engine | Differenzierungsmerkmal; steht — **aber juristisch ungeprüft**, und das Produkt sagt es; Buchungsseite und Templates angeschlossen (C11) |
| WP-31 | Anzeigenvorschläge | steht: wöchentliche Textentwürfe aus dem Brand Guide, HWG-geprüft, Bild auf Wunsch über kie.ai |
| WP-32 | Attribution & ROI-Dashboard | rechtfertigt das Abo; **zwei Sitzungen**, beide stehen — 32a Erfassung und Zuordnung, 32b Kennzahlen, Dashboard und Conversions API |
| WP-32c | Conversions API mit dem Token der Praxis | offen: Token aus der Werbekonto-Verbindung statt Plattformschlüssel (B16), Pixel als Asset in der Login-Konfiguration; sendet erst nach App Review |

## Querschnitt

| | Paket | Hinweis |
|---|---|---|
| WP-33 | Job- und Betriebsinfrastruktur | steht (Virenprüfung, Betriebslage, `mrs:betrieb`, `docs/betrieb.md`); CI-Kette lokal vollständig grün, Sicherung regelt Laravel Cloud |
| WP-34 | Super-Admin-Backoffice | kann WP-05 aushebeln; steht: Übersicht, Mandantenblatt, Sperre, Gutschrift, Impersonation aus dem Blatt |

## Außerhalb des Repositories

- **WP-00 Meta App Review & Business-Verifizierung** — Handarbeit, startet sofort und parallel zu WP-02. Kritischer Pfad des gesamten Projekts. Kein Paket ab WP-19 beginnen, ohne die Berechtigungen zu prüfen.
- **WP-01 Datenschutz-Dokumentation** — AV-Vertrag, TOM, Verzeichnis, Löschkonzept, Prüfung durch einen Medizinrechtler. Voraussetzung für WP-30 und für den ersten zahlenden Kunden. **Offen seit WP-22/23:** Anthropic ist Unterauftragsverarbeiter für Nachrichteninhalte mit Gesundheitsbezug — AV-Vertrag, **Zero Data Retention** und Datenregion gehören in die Unterlagen (Entscheidung G10). *Stand 26.09.2026: wird vom Betreiber extern erstellt.* Aus dem Code dazu gehören: die Anonymisierung lässt die Kanalidentität stehen (WP-19), das Öffnen von Chat-Anhängen wird protokolliert (C12), Stripe und Microsoft sind weitere Empfänger.

## Was außerhalb des Codes noch aussteht

Stand 26.09.2026 — alles, was sich im Repository nicht erledigen lässt:

| | Was | Wo es steht |
|---|---|---|
| **Meta** | App Review (`ads_management`, `ads_read`, `business_management`, WhatsApp), Business-Verifizierung, `META_LOGIN_CONFIG_ID`, Asset-Typ Datensatz in der Login-Konfiguration (B16) | WP-00, WP-32c, `docs/integrationen/meta.md` |
| **Microsoft** | App-Registrierung in Entra ID, Herausgeberüberprüfung | `docs/integrationen/kalender.md`, „Einrichtung Microsoft" |
| **Stripe** | vier Preise anlegen, IDs setzen | `docs/produkt.md`, Preismodell |
| **Datenschutz** | AV, TOM, Verzeichnis, Löschkonzept | WP-01 (extern) |
| **HWG** | juristische Durchsicht des Regelwerks — bewusst offen: die Ampel ist eine Hilfe und sagt es überall | WP-30 |
| **Entscheidung** | `Schedule` und `Contact` an die Conversions API — ja oder nein (C8) | WP-32b, „Offen" |

## Fachlogik

Vier Pakete haben eine eigene, verbindliche Spezifikation. Sie sind vor dem jeweiligen Paket vollständig zu lesen:

| Spezifikation | Gehört zu |
|---|---|
| `../docs/fachlogik/verfuegbarkeit.md` | WP-10 |
| `../docs/fachlogik/agent.md` | WP-22, WP-23, WP-24 |
| `../docs/fachlogik/warteliste.md` | WP-25 |
| `../docs/fachlogik/attribution.md` | WP-32 |

Dazu drei Integrationsleitfäden: `../docs/integrationen/meta.md`, `../docs/integrationen/kalender.md` und `../docs/integrationen/email.md`.
