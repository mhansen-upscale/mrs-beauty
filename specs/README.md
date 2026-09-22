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
| WP-02 | Projekt-Setup | |
| WP-03 | Mandantenfähigkeit & Verschlüsselung | nicht nachrüstbar |
| WP-04 | Benutzer, Rollen, Einladungen | |
| WP-05 | Audit-Log & Impersonation | |
| WP-06 | Abo & Abrechnung | steht: Stripe Billing, eine Stufe + Nutzung, Kontingent + Aufstockung; Preise noch offen |
| WP-07 | Whitelabel | steht: Markenfarbe mit Prüfung, Logo, Impressum und Datenschutz auf der Buchungsseite |

WP-05 bis WP-07 können nach WP-11 nachgezogen werden, falls früh etwas Vorzeigbares gebraucht wird. WP-03 und WP-04 nicht: Mandantentrennung und Verschlüsselung nachträglich einzuziehen bedeutet vollständige Neuverschlüsselung aller Daten.

## M1 · Buchung

| | Paket | Hinweis |
|---|---|---|
| WP-08 | Praxisstammdaten | |
| WP-09 | Leistungskatalog & Terminarten | |
| WP-10 | Verfügbarkeits-Engine | **größter Risikoposten**, Spezifikation zwingend lesen |
| WP-11 | Terminverwaltung intern | |
| WP-12 | Öffentliche Buchungsseite | |
| WP-13 | Erinnerungen & Bestätigungen | |
| WP-14 | Kalendersync Google | |
| WP-15 | Kalendersync Microsoft | erst umsetzen, dann mit WP-14 abstrahieren |

## M2 · Inbox — erster verkaufsfähiger Stand

| | Paket | Hinweis |
|---|---|---|
| WP-16 | Kontakte & Kanalidentitäten | |
| WP-17 | Leads & Pipeline | |
| WP-18 | Notizen, Anhänge, Einwilligungen, Aufbewahrung | |
| WP-19 | Kanal-Infrastruktur | |
| WP-20 | Kanäle (WhatsApp, E-Mail) | zwei Sessions; Messenger und Instagram zurückgestellt (P11) |
| WP-21 | Inbox-Oberfläche | |
| WP-22 | Agent: Klassifikation & Vorschlag | `auto` bleibt gesperrt |
| WP-23 | Agent: Guardrails & Eskalation | eigenes Paket, nicht verkürzen |
| WP-24 | Agent: Automatische Buchung | schaltet `auto` frei |
| WP-25 | Warteliste & Lückenfüllung | |

## M3 · Wachstum

| | Paket | Hinweis |
|---|---|---|
| WP-26 | Ad-Account-Anbindung & Sync | steht: Werbekonto verbinden, Struktur lesend spiegeln, **schreibt bei Meta nichts** — Login-Konfiguration und App Review offen |
| WP-27 | Kampagnenverwaltung | steht: anlegen, pausieren, Budget — schreibend, an **einer** Stelle; braucht `ads_management` |
| WP-27b | Anzeigen schalten | steht: aus freigegebenem Entwurf mit Grafik wird Creative und Anzeige; Kampagne bearbeiten (Budget, Zielgruppe); braucht `ads_management` und eine Facebook-Seite |
| WP-28 | Insights & Aggregation | steht: Tageszahlen je Ebene, Summen und Quoten gerechnet, P9 über die Aufbewahrung |
| WP-29 | Brand Guide | steht: Tonalität, Wortwahl, Referenzmaterial mit Erklärung im Wortlaut; liefert `banned_terms` an WP-30 |
| WP-30 | HWG-Compliance-Engine | Differenzierungsmerkmal; steht — **aber juristisch ungeprüft**, und das Produkt sagt es |
| WP-31 | Anzeigenvorschläge | steht: wöchentliche Textentwürfe aus dem Brand Guide, HWG-geprüft, Bild auf Wunsch über kie.ai |
| WP-32 | Attribution & ROI-Dashboard | rechtfertigt das Abo; **zwei Sitzungen**, beide stehen — 32a Erfassung und Zuordnung, 32b Kennzahlen, Dashboard und Conversions API |

## Querschnitt

| | Paket | Hinweis |
|---|---|---|
| WP-33 | Job- und Betriebsinfrastruktur | Grundlage steht (Virenprüfung, Betriebslage, `mrs:betrieb`, `docs/betrieb.md`); wächst mit M3 weiter |
| WP-34 | Super-Admin-Backoffice | kann WP-05 aushebeln; steht: Übersicht, Mandantenblatt, Sperre, Gutschrift — Impersonation aus dem Blatt fehlt noch |

## Außerhalb des Repositories

- **WP-00 Meta App Review & Business-Verifizierung** — Handarbeit, startet sofort und parallel zu WP-02. Kritischer Pfad des gesamten Projekts. Kein Paket ab WP-19 beginnen, ohne die Berechtigungen zu prüfen.
- **WP-01 Datenschutz-Dokumentation** — AV-Vertrag, TOM, Verzeichnis, Löschkonzept, Prüfung durch einen Medizinrechtler. Voraussetzung für WP-30 und für den ersten zahlenden Kunden. **Offen seit WP-22/23:** Anthropic ist Unterauftragsverarbeiter für Nachrichteninhalte mit Gesundheitsbezug — AV-Vertrag, **Zero Data Retention** und Datenregion gehören in die Unterlagen (Entscheidung G10).

## Fachlogik

Vier Pakete haben eine eigene, verbindliche Spezifikation. Sie sind vor dem jeweiligen Paket vollständig zu lesen:

| Spezifikation | Gehört zu |
|---|---|
| `../docs/fachlogik/verfuegbarkeit.md` | WP-10 |
| `../docs/fachlogik/agent.md` | WP-22, WP-23, WP-24 |
| `../docs/fachlogik/warteliste.md` | WP-25 |
| `../docs/fachlogik/attribution.md` | WP-32 |

Dazu drei Integrationsleitfäden: `../docs/integrationen/meta.md`, `../docs/integrationen/kalender.md` und `../docs/integrationen/email.md`.
