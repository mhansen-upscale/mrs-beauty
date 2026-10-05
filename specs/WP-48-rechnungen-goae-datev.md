# WP-48 · Rechnungen nach GOÄ, mit DATEV-Export

> „Ganz einfache Rechnungsgeschichten": Nach der Behandlung geht jemand rein,
> erstellt die Rechnung und schickt sie per Mail oder WhatsApp, und die
> Buchhaltung exportiert einmal im Monat im DATEV-Format. „Mehr nicht." So
> machen es Kliniko und Doctolib. Entscheidung **P14** löst P4 ab. Weil die
> Pilotpraxen ärztlich geführt sind, gilt **die GOÄ von Anfang an**: Für
> Ärzte ist sie auch bei ästhetischen Leistungen bindend, ein Pauschalpreis
> ist unzulässig.

## Ziel
Aus einem erschienenen Termin wird mit wenigen Klicks eine Rechnung:
- in einer ärztlichen Praxis nach GOÄ, sonst mit freien Positionen
- mit lückenloser Nummer, unveränderbar nach Ausstellung, Korrektur per Storno
- versendet über das Postfach der Praxis
- monatlich als DATEV-Buchungsstapel für die Buchhaltung

**Zwei Sitzungen:**

| | Sitzung | Inhalt |
|---|---|---|
| 48a | Rechnung und GOÄ | Gebührenverzeichnis, Rechnungsvorlage je Behandlung, Entwurf, Ausstellung, Nummernkreis, PDF, Storno, Zahlungsstand |
| 48b | Versand und DATEV | Mail, WhatsApp, Buchungsstapel, Kontenzuordnung, Exportprotokoll, Umsatz in der Auswertung |

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussage 18
- `docs/entscheidungen.md`: **P14, C20, P2, B22, C17, D14, A5**
- CLAUDE.md, **Regeln 1–4**
- `specs/WP-06b-paketverwaltung.md`: Fassungen append-only per Trigger, das Muster für „unveränderbar"
- `specs/WP-32b-roi-dashboard.md`: Umsatz heute als Schätzung
- `specs/WP-47-patientenakte.md`: die Praxisart; wer zuerst baut, legt sie an

## Voraussetzungen
- WP-44 (Kontaktseite, Reiter „Rechnungen").
- **Steuerliche Prüfung angestoßen** (`specs/README.md`, „Außerhalb des Repositories"): GOÄ-Abbildung, Umsatzsteuer, GoBD, DATEV-Konten, KassenSichV.
  Freigeschaltet wird nach der Prüfung (`mrs.rechnung.freigegeben`, Vorgabe `false`).

## Die Linie, an der alles hängt

**Eine Rechnung ist ein Beleg, kein Datensatz.** Ab der Ausstellung ändert
sie nichts und niemand mehr, weder Code noch Konsole noch Betreiber. Das sichert
ein Trigger wie bei den Paketfassungen. Wer korrigiert, storniert und stellt
neu aus (GoBD). Die Nummer ist je Praxis fortlaufend und lückenlos, auch wenn
zwei Personen gleichzeitig ausstellen.

**GOÄ ist Rechnen, nicht Preisliste.** Eine Gebühr ist Punktzahl × Punktwert
(5,82873 Cent, § 5 Abs. 1 GOÄ) × Steigerungsfaktor, gerundet erst am Ende.
- Über dem Schwellenwert braucht sie eine Begründung (§ 12 Abs. 3).
- Über dem Höchstsatz geht nur eine Honorarvereinbarung nach § 2; die ist nicht in diesem Paket.
- Nicht im Verzeichnis stehende Leistungen werden analog berechnet (§ 6 Abs. 2) und so bezeichnet.
- Material wie Botulinumtoxin oder Filler sind Auslagen (§ 10).

Damit das „ganz einfach" bleibt, trägt jede Behandlung eine **Rechnungsvorlage**:
die üblichen Positionen mit Faktor und Auslagen. Nach dem Termin ist die
Rechnung ein Entwurf aus dieser Vorlage, den man prüft und ausstellt.

**Keine Kasse.** Das Produkt nimmt kein Geld an und verbucht kein Bargeld.
Der Zahlungsstand wird von Hand gesetzt, mit den Zahlarten Überweisung oder
Karte, nicht „bar". Ein elektronisches Aufzeichnungssystem mit Kassenfunktion
bräuchte eine TSE (KassenSichV). Bargeld gehört in die Kasse der Praxis.

**An die Buchhaltung gehen Beträge** (C20). Die Rechnung an die Patientin
nennt die Leistungen, wie § 12 GOÄ es verlangt. Der DATEV-Export trägt
Nummer, Datum, Betrag, Steuersatz, Konto und Debitor, weder GOÄ-Text noch
Behandlung.

## Datenmodell (48a)

| Tabelle | Inhalt |
|---|---|
| `fee_schedules` | Fassungen des Gebührenverzeichnisses: `name` (GOÄ), `valid_from`, `point_value` (Zehntausendstel-Cent als Ganzzahl, keine Fließkommazahl) |
| `fee_items` | je Fassung: Nummer, Bezeichnung, Punktzahl, Gebührenart (persönlich, technisch, Labor) für Schwelle und Höchstsatz; global, nicht je Praxis (wie C1) |
| `invoice_templates` | je Behandlung (`treatment_id`) die Vorlage: Positionen (Nummer, Faktor, Anzahl, Begründung, analog-Bezug) und Auslagen |
| `invoices` | `organization_id`, `contact_id`, `appointment_id` (nullable), `number` (nach Ausstellung), `status` (`draft`, `issued`, `cancelled`), `issued_at`, Summen je Steuersatz, `cancels_invoice_id`, Rechnungsanschrift als Abzug (verschlüsselt), `pdf_attachment_id`, `pdf_sha256` |
| `invoice_items` | Positionen: Art (`goae`, `goae_analog`, `expense`, `sale`, `free`), Nummer, Bezeichnung, Punktzahl, Faktor, Betrag in Cent, Steuersatz, Begründung, Leistungsdatum |
| `invoice_number_sequences` | je Praxis und Kreis der nächste Wert, gesperrt mit `FOR UPDATE` |
| `invoice_payments` | Zahlungsstand: Datum, Zahlart (`transfer`, `card`), Betrag; von Hand |

- Die Rechnungsanschrift kommt als neue verschlüsselte Felder an den Kontakt (Straße, PLZ, Ort; Regel 3). Die Rechnung friert sie als Abzug ein, denn eine spätere Adressänderung ändert keinen Beleg.
- **Praxisstammdaten** bekommen Steuernummer oder USt-IdNr., Bankverbindung und Rechnungsfußzeile (WP-08). Ohne Steuernummer lässt sich keine Rechnung ausstellen.
- Die GOÄ-Daten sind ein amtliches Werk (§ 5 UrhG). Sie werden als Seeder aus einer Fassung mit Datum eingespielt, mit Fundstelle.

## Schritte

**48a**
1. Abnahmekriterien 1–17 als Tests.
2. Schema mit Trigger:
   - `invoices`: in `issued` und `cancelled` keine Änderung außer an `invoice_payments`
   - `invoice_items` einer ausgestellten Rechnung: nicht änderbar
3. **`Gebuehrenrechner`:**
   - Betrag = Punktzahl × Punktwert × Faktor, kaufmännisch auf Cent gerundet **am Ende**
   - Schwelle und Höchstsatz je Gebührenart aus `config/mrs.php` (`rechnung.goae`, mit Fundstelle § 5 Abs. 2–4)
4. **Rechnungsvorlage** an der Behandlung (`katalog/Behandlungen.vue`): Positionen wählen, Faktor setzen, Begründung als Textbaustein, Auslagen mit Vorschlagsbetrag.
5. **Entwurf aus dem Termin:**
   - „Rechnung" am erschienenen Termin legt einen Entwurf aus der Vorlage an, Leistungsdatum = Termin
   - im Entwurf frei änderbar
6. **Ausstellen:**
   - Pflichtangaben prüfen (§ 14 Abs. 4 UStG, § 12 Abs. 2 GOÄ), Nummer ziehen
   - PDF erzeugen und verschlüsselt ablegen (`Anhangspeicher`), Hash festhalten
   - alles in **einer** Transaktion
7. **Storno:**
   - eine Stornorechnung mit eigener Nummer, Verweis auf das Original und negativen Beträgen
   - das Original steht danach auf `cancelled`
8. **Zahlungsstand** von Hand; die Übersicht „Offene Rechnungen" auf der Kontaktseite und unter *Praxis → Rechnungen*.
9. **Freie Positionen** für `heilpraktik` und `kosmetik`: Bezeichnung, Betrag, Steuersatz; die GOÄ-Felder entfallen.

**48b**
10. Abnahmekriterien 18–28 als Tests.
11. **Versand per Mail:**
    - über das Postfach der Praxis (B22), als Auftrag (B21), PDF im Anhang
    - Betreff und Text ohne Behandlung (C17)
    - ohne Postfach: `no_mailer`, mit Hinweis an der Rechnung
12. **Versand per WhatsApp** (siehe „Offen"): als Dokument im offenen Antwortzeitraum; außerhalb nur mit Mail.
13. **DATEV-Buchungsstapel:**
    - EXTF-Format (Fassung 700, CSV, Windows-1252, Semikolon)
    - Kopf mit Berater- und Mandantennummer, Wirtschaftsjahresbeginn und Sachkontenlänge aus den Praxiseinstellungen
    - je Rechnung und Steuersatz eine Zeile: Umsatz, Soll/Haben, Konto (Debitor), Gegenkonto (Erlöskonto je Steuersatz), BU-Schlüssel, Belegdatum, Belegfeld 1 = Rechnungsnummer, Buchungstext „Rechnung {Nummer}"
    - Storno als Gegenbuchung
14. **Kontenzuordnung** je Praxis:
    - SKR03 oder SKR04, Vorschläge SKR03 8400 / 8100, SKR04 4400 / 4100, vom Steuerberater zu bestätigen
    - Sammeldebitor als Vorgabe (C20), Personenkonten nur auf ausdrücklichen Wunsch
15. **Exportprotokoll:**
    - Zeitraum, Person, Zeit, Anzahl, Hash der Datei
    - derselbe Zeitraum ergibt dieselbe Datei
    - maßgeblich ist das Rechnungsdatum, nicht das Leistungsdatum; weil eine Rechnung nicht zurückdatiert werden kann, ändert sich ein exportierter Monat nicht mehr
16. **Auswertung:**
    - wo eine Rechnung zum Termin besteht, nimmt WP-32b deren Nettobetrag statt der Schätzung (D14), und die Spalte sagt „abgerechnet"
    - an Meta geht kein Wert (die Conversions API sendet weiter nur Lead, Schedule und Contact)
17. **Aufbewahrung:** Datenart `invoice` in `retention` und `RetentionPolicy`, 8 Jahre (§ 147 Abs. 3 AO in der Fassung ab 2025), Fristbeginn mit Ende des Kalenderjahres; von Hand durchgesetzt (C19).
18. **Fähigkeiten:**
    - `invoices.manage` (Inhaberin, Verwaltung, Empfang): Entwurf, Ausstellen, Versand, Zahlungsstand
    - `invoices.export` (Inhaberin, Verwaltung): DATEV
    - Behandlerin und Marketing: keine

## Abnahmekriterien

**48a · Rechnung und GOÄ**

1. GOÄ Nr. 1 (80 Punkte) zum 2,3-fachen ergibt 10,72 €; Nr. 3 (150 Punkte) zum 2,3-fachen ergibt **20,11 €**, nicht 20,10 €, denn gerundet wird erst am Ende.
2. Ein Faktor über dem Schwellenwert ohne Begründung lässt sich nicht ausstellen; mit Begründung steht sie auf der Rechnung an der Position.
3. Ein Faktor über dem Höchstsatz wird abgewiesen, mit dem Hinweis auf die Honorarvereinbarung nach § 2.
4. Eine Analogposition trägt „analog" und die Nummer der gleichwertigen Leistung auf der Rechnung.
5. Auslagen stehen als eigene Positionen ohne Faktor; über 25,56 € verlangt das Produkt den Vermerk, dass ein Beleg beiliegt.
6. Eine Rechnung mit einer Position zu 19 % und einer steuerfreien weist beide Summen getrennt aus und trägt den Hinweis auf § 4 Nr. 14 Buchst. a UStG.
7. Ohne Steuernummer der Praxis oder ohne Rechnungsanschrift der Patientin scheitert das Ausstellen mit einer Meldung am fehlenden Feld.
8. Zwei gleichzeitige Ausstellungen derselben Praxis ergeben zwei aufeinanderfolgende Nummern, ohne Lücke und ohne Doppel (Test mit zwei Verbindungen, MySQL).
9. Ein gescheitertes Ausstellen (PDF-Fehler) verbraucht keine Nummer.
10. Ein `UPDATE` auf eine ausgestellte Rechnung oder ihre Positionen scheitert am Trigger, auch per SQL. Der Zahlungsstand bleibt änderbar.
11. Das PDF einer ausgestellten Rechnung hat den festgehaltenen Hash. Eine spätere Adressänderung am Kontakt ändert weder Rechnung noch PDF.
12. Storno erzeugt eine Rechnung mit eigener Nummer, negativen Beträgen und Verweis. Das Original steht auf `cancelled`, ein zweites Storno wird abgewiesen.
13. „Rechnung" an einem erschienenen Termin legt einen Entwurf mit den Positionen der Rechnungsvorlage seiner Behandlung an; an einem abgesagten Termin gibt es den Knopf nicht.
14. Die Zahlart „bar" gibt es nicht.
15. In einer Praxis `kosmetik` gibt es keine GOÄ-Felder, nur freie Positionen. In einer Praxis `aerztlich` ist eine ärztliche Leistung nur als GOÄ-Position möglich; freie Positionen sind als Auslage oder als Verkauf (etwa Pflegeprodukte) gekennzeichnet.
16. Eine Rechnung einer fremden Praxis ergibt 404; der Nummernkreis ist je Praxis getrennt (Regel 1).
17. Mit `mrs.rechnung.freigegeben = false` gibt es keine Rechnungsrouten.

**48b · Versand und DATEV**

18. Der Mailversand läuft als Auftrag über das Postfach der Praxis. Betreff und Text enthalten keinen Katalognamen und keine GOÄ-Bezeichnung (Prüfung gegen `Treatment::aktiveNamen()` und die Bezeichnungen aus `fee_items`).
19. Ohne Postfach steht die Rechnung auf „nicht versendet: kein Postfach", und die Kontaktseite zeigt das.
20. Per WhatsApp geht die Rechnung nur im offenen Antwortzeitraum hinaus. Außerhalb ist der Knopf gesperrt, mit Hinweis auf die Mail.
21. Der Buchungsstapel beginnt mit dem EXTF-Kopf, ist Windows-1252-kodiert und enthält je Rechnung und Steuersatz genau eine Zeile.
22. Kein Buchungstext enthält einen Katalognamen oder eine GOÄ-Bezeichnung (C20); die Prüfung läuft wie in Kriterium 18.
23. Ein Storno erscheint als Gegenbuchung im Monat des Stornos.
24. Ein abgeschlossener Monat zweimal exportiert ergibt dieselbe Datei, Byte für Byte; das Ausstellungsdatum lässt sich nicht in die Vergangenheit setzen.
25. Eine Rechnung erscheint im Export des Monats ihres Rechnungsdatums; eine am 02.11. ausgestellte Rechnung für eine Behandlung am 30.10. steht im November.
26. Mit Sammeldebitor enthält der Export keinen Patientennamen.
27. In der Auswertung (WP-32b) zählt bei einem Termin mit Rechnung deren Nettobetrag; ohne Rechnung bleibt die Schätzung, gekennzeichnet.
28. Keine Conversions-API-Nachricht trägt einen Rechnungsbetrag (`AuswertungTest` unverändert grün).

## Nicht in diesem Paket
- Honorarvereinbarung nach § 2 GOÄ (abweichender Faktor über dem Höchstsatz, schriftlich vor der Behandlung).
- Übertragung an DATEV per Schnittstelle; als Auftrag nach Regel 4 später.
- Belegbilder im Export, Mahnwesen, Zahlungsabwicklung, Kassenfunktion, Anzahlungen (P2).
- E-Rechnung (für Rechnungen an Privatpersonen keine Pflicht), Rechnungen an Firmen.
- Kassenabrechnung (GKV).
- Die neue GOÄ. Sie kommt als neue Fassung in `fee_schedules`, nicht als Umbau.

## Offen — vor dem Bau zu entscheiden
- **WhatsApp als Weg für die Rechnung.** Gesprächsinhalte laufen schon heute über WhatsApp, und der Assistent nennt dort Behandlungen (`Dialogtexte::behandlungFragen`). Eine Rechnung ist aber ein Beleg mit allen Leistungen. Vorschlag: zulassen, nur im offenen Zeitraum, nur mit Einwilligung in den Kanal; mit dem Datenschutz (WP-01) bestätigen.
- **Konten und BU-Schlüssel**, Sammeldebitor oder Personenkonten: Steuerberater.
- **GOÄ-Vorlagen für die üblichen ästhetischen Leistungen** (Beratung, Injektion analog, Auslagen): mit den Pilotpraxen festlegen; das Produkt liefert keine Abrechnungsempfehlung, sondern die Vorlage der Praxis.

## Fallstricke
- **Fließkomma tötet Belege.** Punktwert und Beträge rechnen in Ganzzahlen (Zehntausendstel-Cent und Cent); Kriterium 1 fängt den Rundungsfehler.
- **Die Nummer gehört zur Ausstellung, nicht zum Entwurf.** Ein Entwurf hat keine Nummer, sonst entstehen beim Verwerfen Lücken.
- **„Unveränderbar" schließt den Betreiber ein.** Auch eine Korrektur im Support läuft über Storno. Eine Konsole, die eine Rechnung „repariert", bricht die GoBD.
- **Die Krypto-Löschung einer Praxis (A5) vernichtet Belege mit Aufbewahrungspflicht.** Wie bei der Akte (WP-47, Schritt 8) gibt es vorher eine Gesamtausgabe aller Rechnungen samt DATEV-Export.
- **Ein Katalogpreis „ab 250 €" ist bei Ärzten keine Rechnung.** Die Buchungsseite darf ihn als Orientierung zeigen (C11); die Rechnung rechnet nach GOÄ.
