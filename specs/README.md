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
| WP-06b | Paketverwaltung | steht: Name, Preise und Kontingente im Backoffice, in Fassungen (append-only per Trigger); Stripe-Preise per Auftrag, **ohne Stripe-Schlüssel gilt eine Fassung sofort (Testbetrieb)**; Bestand je Änderung zum nächsten Zeitraum umstellbar (B20); Kasse, Kontingente und MRR je Abo aus dessen Fassung |
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
| WP-26 | Ad-Account-Anbindung & Sync | steht: Werbekonto verbinden, Struktur lesend spiegeln, **schreibt bei Meta nichts**; App Review und Login-Konfiguration durch (28.09.2026) |
| WP-27 | Kampagnenverwaltung | steht: anlegen, pausieren, Budget — schreibend, an **einer** Stelle; `ads_management` erteilt |
| WP-27b | Anzeigen schalten | steht: aus freigegebenem Entwurf mit Grafik wird Creative und Anzeige; Kampagne bearbeiten (Budget, Zielgruppe); `ads_management` erteilt, braucht eine Facebook-Seite |
| WP-28 | Insights & Aggregation | steht: Tageszahlen je Ebene, Summen und Quoten gerechnet, P9 über die Aufbewahrung |
| WP-29 | Brand Guide | steht: Tonalität, Wortwahl, Referenzmaterial mit Erklärung im Wortlaut; liefert `banned_terms` an WP-30 |
| WP-30 | HWG-Compliance-Engine | Differenzierungsmerkmal; steht; juristisch durchgesehen (27.09.2026), im Produkt **bewusst nur Prüfhilfe**, ohne Prüfsiegel (C18); Buchungsseite, Templates und Mailvorlagen angeschlossen (C11) |
| WP-31 | Anzeigenvorschläge | steht: wöchentliche Textentwürfe aus dem Brand Guide, HWG-geprüft, Bild auf Wunsch über kie.ai |
| WP-31b | Anzeigenformate | Nachtrag zu WP-31 und WP-27b: jede Grafik in 1:1, 4:5 und 9:16, bei Meta je Platzierung zugeordnet (C13); ein Formatsatz zählt als eine Grafik (B13) |
| WP-32 | Attribution & ROI-Dashboard | rechtfertigt das Abo; **zwei Sitzungen**, beide stehen — 32a Erfassung und Zuordnung, 32b Kennzahlen, Dashboard und Conversions API |
| WP-32c | Conversions API mit dem Token der Praxis | offen: Token aus der Werbekonto-Verbindung statt Plattformschlüssel (B16), Pixel als Asset in der Login-Konfiguration; die Voraussetzungen bei Meta sind erfüllt (28.09.2026), das Paket wirkt sofort |

## Querschnitt

| | Paket | Hinweis |
|---|---|---|
| WP-33 | Job- und Betriebsinfrastruktur | steht (Virenprüfung, Betriebslage, `mrs:betrieb`, `docs/betrieb.md`); CI-Kette lokal vollständig grün, Sicherung regelt Laravel Cloud |
| WP-34 | Super-Admin-Backoffice | kann WP-05 aushebeln; steht: Übersicht, Mandantenblatt, Sperre, Gutschrift, Impersonation aus dem Blatt |
| WP-34a | Betreiberrollen & Anmeldung | steht: Super-Admin, Customer Success, Finanzen (C14), eigener Eingang `/backoffice/anmelden` mit Drosselung, Leerlauf und Passwort vor jeder Handlung, Betreiberverwaltung und -protokoll; **die Sperre wirkt jetzt** (WP-34 AK 8), auch für den Agenten und in der Aufbewahrung |
| WP-34b | Freigabe per Einmal-PIN | steht: die Inhaberin gibt den Vollzugriff unter *Team* auch per Einmal-PIN frei (C15), sechs Stellen, 15 Minuten, einmal, verbrennt nach fünf Fehlversuchen, gedrosselt je Betreiber über alle Praxen; die Praxis sieht laufenden Vollzugriff und beendet ihn; die PIN steht in keinem Protokoll |
| WP-34c | Abo-Eingriffe & Testphase | steht: pausieren, kündigen, Rücknahme, Gratismonat als Auftrag bei Stripe (B17), **ohne Stripe-Schlüssel sofort lokal (Testbetrieb)**; Ende der Testphase durchgesetzt mit Gnadenfrist (B18); Webhook mit Dedupe und Reihenfolge; Agent und Buchungsseite ruhen bei Abo-Sperre |
| WP-34d | Finanzübersicht | steht: Einnahmen, Kosten und Ergebnis je Monat und Praxis als Hochrechnung unter *Backoffice → Finanzen* (B19), Grundpreis aus der Fassung, Kostensätze aus der Umgebung und leer „nicht hinterlegt“; `mrs:monatsabschluss` als Historie, Kasten „Wirtschaftlichkeit“ im Mandantenblatt; nur `finanzen.sehen` |
| WP-35 | Zweiter Faktor | steht: freiwillig für Praxen und Betreiber, per Authenticator-App oder E-Mail-Code, Hinweis statt Pflicht (C16); Zurücksetzen über Team, Betreiberverwaltung oder Konsole; nach WP-34a |
| WP-36 | Mails der Praxis | steht: Texte und Aussehen der fünf Terminmails unter *Einstellungen → E-Mails* mit Vorschau, Probemail und Zurücksetzen (P12, C17, D15); Versand **nur** über das Postfach der Praxis (B22, A15), ohne Postfach `no_mailer` mit Hinweis im Produkt; nach WP-07, WP-13, WP-20b |
| WP-37 | Plattformmails & Versand des Betreibers | steht: Mailserver im Backoffice, gilt erst nach der Probemail, `.env` als Rückfall und bei Störung (B23); Texte und Aussehen der Produktmails unter *Backoffice → E-Mails*; nur Super-Admin; nach WP-36 und WP-34a |
| WP-38 | Öffentliche Startseite, Impressum, Datenschutzerklärung | steht: Startseite unter `/` mit Preisen aus der geltenden Paketfassung und Demo-Anfrage (gespeichert, verschlüsselt, ohne Freitext; Plattformmail an `VERTRIEB_ADRESSE` ohne Angaben), Impressum und Datenschutzerklärung (`/datenschutzerklaerung`), kein Tracking; Demo-Anfragen im Backoffice, Frist über `mrs:aufbewahrung`; nach WP-06b, WP-34a, WP-37 |
| WP-39 | Erste Schritte im Dashboard | **offen, Spezifikation zur Durchsicht** (29.09.2026): Kasten mit zwölf Einrichtungsschritten, aus den Daten abgeleitet statt abgehakt, je Fähigkeit sichtbar, nötig und empfohlen; ehrlicher Buchungslink; eine Stelle für „buchbar“; nach WP-07 bis WP-34c |

## Außerhalb des Repositories

- **WP-00 Meta App Review & Business-Verifizierung** — **erledigt** (Stand 28.09.2026): App Review, Business-Verifizierung und Login-Konfiguration samt Asset-Typ Datensatz.
- **WP-01 Datenschutz-Dokumentation** — AV-Vertrag, TOM, Verzeichnis, Löschkonzept, Prüfung durch einen Medizinrechtler. Voraussetzung für den ersten zahlenden Kunden. **Offen seit WP-22/23:** Anthropic ist Unterauftragsverarbeiter für Nachrichteninhalte mit Gesundheitsbezug — AV-Vertrag, **Zero Data Retention** und Datenregion gehören in die Unterlagen (Entscheidung G10). *Stand 28.09.2026: in Arbeit beim Betreiber.* Aus dem Code dazu gehören: die Anonymisierung lässt die Kanalidentität stehen (WP-19), das Öffnen von Chat-Anhängen wird protokolliert (C12), Stripe und Microsoft sind weitere Empfänger; Mails an Patientinnen gehen über das Postfach der Praxis (B22), Produktmails über den Mailserver, den der Betreiber hinterlegt (B23).

## Was außerhalb des Codes noch aussteht

Stand 28.09.2026 — alles, was sich im Repository nicht erledigen lässt:

| | Was | Wo es steht |
|---|---|---|
| **Microsoft** | App-Registrierung in Entra ID, Herausgeberüberprüfung | `docs/integrationen/kalender.md`, „Einrichtung Microsoft" |
| **Stripe** | je Umgebung `STRIPE_SECRET` eintragen, `php artisan mrs:stripe-einrichten` ausführen (Webhook auf der festen API-Version, Gutschein, Kundenportal) und das ausgegebene `STRIPE_WEBHOOK_SECRET` eintragen; im Backoffice unter *Paket* die Fassung einmal speichern, auch unverändert — sie legt Produkt und Preise an; im Dashboard **Stripe Tax** mit Registrierung Deutschland, **SEPA-Lastschrift** und bei fehlgeschlagenen Zahlungen „Abo als **unbezahlt** markieren" | `docs/betrieb.md`; WP-06, WP-06b, WP-34c |
| **Entscheidung** | `Schedule` und `Contact` an die Conversions API — ja oder nein (C8) | WP-32b, „Offen" |
| **Entscheidung** | Rollenkatalog der Betreiber bestätigen; Agent und Buchungsseite bei Abo-Sperre (B17); Praxen in der Testphase wechseln immer auf die neue Paketfassung (B20) | WP-34a, WP-34c, WP-06b |
| **Mailversand** | **Vor dem Ausrollen von WP-36 jede Praxis ohne eigenes Postfach informieren** — ab dann gehen ihre Terminmails, Posteingang-Antworten und Wartelisten-Angebote nicht mehr über die Plattform (B22); die Zahl steht im Betreiber-Dashboard. Im Backoffice unter *Versand* den Plattformserver hinterlegen und mit der Probemail prüfen (B23) | WP-36, WP-37, `docs/betrieb.md` |
| **Startseite** | `VERTRIEB_ADRESSE` je Umgebung setzen (ohne Wert: info@mrs-beauty.ai); Impressum und Datenschutzerklärung juristisch prüfen lassen, dabei Hosting, Mailversand und Fehlerüberwachung namentlich ergänzen; im Backoffice unter *Versand* die Impressum- und Datenschutz-Adresse der Produktmails auf `/impressum` und `/datenschutzerklaerung` setzen; Frist (12 Monate) und Customer Success als Leser bestätigen | WP-38, `docs/betrieb.md` |

## Fachlogik

Vier Pakete haben eine eigene, verbindliche Spezifikation. Sie sind vor dem jeweiligen Paket vollständig zu lesen:

| Spezifikation | Gehört zu |
|---|---|
| `../docs/fachlogik/verfuegbarkeit.md` | WP-10 |
| `../docs/fachlogik/agent.md` | WP-22, WP-23, WP-24 |
| `../docs/fachlogik/warteliste.md` | WP-25 |
| `../docs/fachlogik/attribution.md` | WP-32 |

Dazu drei Integrationsleitfäden: `../docs/integrationen/meta.md`, `../docs/integrationen/kalender.md` und `../docs/integrationen/email.md`.
