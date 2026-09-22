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
| WP-06 | Abo & Abrechnung | nachrangig |
| WP-07 | Whitelabel | nachrangig |

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
| WP-20 | Kanäle (E-Mail, WhatsApp, Instagram, Messenger) | vier Sessions |
| WP-21 | Inbox-Oberfläche | |
| WP-22 | Agent: Klassifikation & Vorschlag | `auto` bleibt gesperrt |
| WP-23 | Agent: Guardrails & Eskalation | eigenes Paket, nicht verkürzen |
| WP-24 | Agent: Automatische Buchung | schaltet `auto` frei |
| WP-25 | Warteliste & Lückenfüllung | |

## M3 · Wachstum

| | Paket | Hinweis |
|---|---|---|
| WP-26 | Ad-Account-Anbindung & Sync | |
| WP-27 | Kampagnenverwaltung | |
| WP-28 | Insights & Aggregation | |
| WP-29 | Brand Guide | |
| WP-30 | HWG-Compliance-Engine | Differenzierungsmerkmal |
| WP-31 | Anzeigenvorschläge | |
| WP-32 | Attribution & ROI-Dashboard | rechtfertigt das Abo |

## Querschnitt

| | Paket | Hinweis |
|---|---|---|
| WP-33 | Job- und Betriebsinfrastruktur | wächst mit, abschließend nach WP-32 |
| WP-34 | Super-Admin-Backoffice | kann WP-05 aushebeln |

## Außerhalb des Repositories

- **WP-00 Meta App Review & Business-Verifizierung** — Handarbeit, startet sofort und parallel zu WP-02. Kritischer Pfad des gesamten Projekts. Kein Paket ab WP-19 beginnen, ohne die Berechtigungen zu prüfen.
- **WP-01 Datenschutz-Dokumentation** — AV-Vertrag, TOM, Verzeichnis, Löschkonzept, Prüfung durch einen Medizinrechtler. Voraussetzung für WP-30 und für den ersten zahlenden Kunden.

## Fachlogik

Vier Pakete haben eine eigene, verbindliche Spezifikation. Sie sind vor dem jeweiligen Paket vollständig zu lesen:

| Spezifikation | Gehört zu |
|---|---|
| `../docs/fachlogik/verfuegbarkeit.md` | WP-10 |
| `../docs/fachlogik/agent.md` | WP-22, WP-23, WP-24 |
| `../docs/fachlogik/warteliste.md` | WP-25 |
| `../docs/fachlogik/attribution.md` | WP-32 |

Dazu zwei Integrationsleitfäden: `../docs/integrationen/meta.md` und `../docs/integrationen/kalender.md`.
