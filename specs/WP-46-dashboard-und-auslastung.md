# WP-46 · Dashboard und Auslastung

> „Wäre cool, für so eine Praxis zu sehen, wie ausgelastet sie an welchem
> Standort gerade ist und welcher Behandler wie ausgelastet ist" — im Video
> zweimal gesagt. Heute rechnet das Dashboard jede Zahl für die ganze Praxis,
> nichts je Standort. „Heute" kommt aus der Zeitzone des alphabetisch ersten
> Standorts. Eine Auslastung gibt es nirgends: weder in `app/` noch in
> `docs/` noch in `config/`.

## Ziel
Das Dashboard zeigt die Auslastung für die ganze Praxis, je Standort, je
Behandler und, mit WP-45, je Raum, für heute, diese Woche und die nächsten
vier Wochen. Jede Zahl gilt im Tag ihres Standorts, und die übrigen Kacheln
sprechen Praxis (WP-40).

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 2 und 13, Beobachtung B3
- `specs/WP-39-erste-schritte.md`: offen, gehört auf dieselbe Seite
- `app/Betrieb/Praxiskennzahlen.php`, `app/Http/Controllers/Betrieb/DashboardController.php`, `resources/js/pages/Dashboard.vue`
- `docs/fachlogik/verfuegbarkeit.md`: V1–V3 (Arbeitszeit, Abwesenheit, Schließung) und V11 (Puffer)
- Entscheidungen **A7, A8, D16**; CLAUDE.md, Arbeitsweise „Zeit"

## Voraussetzungen
WP-40, WP-44 (die Kachel „Neue Anfragen und Buchungen"). Für die
Raumauslastung WP-45; ohne Räume entfällt nur dieser Teil.

## Die Definition

**Auslastung = belegte Minuten / verfügbare Minuten**, je Behandler und
Standort und Tag, und daraus summiert. Summiert werden Minuten, nicht Quoten:
Ein Standort mit einem vollen und einem leeren Behandler ist zu 50 %
ausgelastet, nicht im Mittel zweier Quoten.

- **Verfügbar:**
  - die Arbeitszeit des Behandlers an diesem Standort und Tag (`WorkingHour`, V1)
  - abzüglich Abwesenheiten (`Absence`, V2) und Schließungen des Standorts (`LocationClosure`, V3)
  - in Ortszeit des Standorts gerechnet, in UTC verglichen (A7, A8)
- **Belegt:**
  - Termine mit Status `pending`, `confirmed`, `attended` oder `no_show`, mit Puffern (`belegteDauer()`), beschnitten auf die verfügbare Zeit
  - Ein nicht erschienener Termin hat die Zeit belegt; abgesagte Termine (`cancelled`) zählen nicht.
- **Extern belegt** (`ExternalCalendarBlock`, V4): Zeit, die ein privater Kalendereintrag blockiert. **Getrennt** ausgewiesen, nicht als Auslastung: Ein Zahnarzttermin der Ärztin ist keine Auslastung der Praxis, aber auch keine freie Zeit.
  Darstellung: „frei" = verfügbar − belegt − extern belegt.
- **Raum** (WP-45):
  - belegte Minuten des Raums / Arbeitszeit am Standort
  - die Arbeitszeit am Standort ist die Vereinigung der Arbeitszeiten aller Behandler dort; eigene Öffnungszeiten gibt es nicht (WP-08)
  - Doppelbelegung zählt doppelt, die Quote kann also über 100 % liegen und wird so gezeigt, mit Hinweis

**Nicht aus `appointment_slots` rechnen.** Die Slotzeilen bilden Abwesenheiten
und Schließungen nicht ab (V2 und V3 werden beim Abfragen geprüft), und
übersteuerte Termine erzeugen Zeilen außerhalb der Arbeitszeit
(`Slotbelegung::ergaenzeZeilen`).

## Schritte
1. Die Definition oben als Testfälle (unten) schreiben, **vor** dem Code.
2. **`app/Betrieb/Auslastung.php`:**
   - `fuer(Zeitraum $zeitraum, ?Location $standort = null, ?Practitioner $behandler = null): Auslastungssatz`
   - der Satz enthält Minuten verfügbar, belegt, extern belegt und frei, je Tag und summiert
   - eine Abfrage je Tabelle und Zeitraum, nicht je Tag
3. **Zeitraum je Standort:** „heute" und „diese Woche" sind Tage in der Zeitzone **des Standorts**. Die Summe über Standorte summiert die Tage der Standorte, nicht einen gemeinsamen UTC-Tag. Derselbe Zeitzonenfehler in `Praxiskennzahlen::zone()` (Z. 178-189) wird mit behoben: Jede Kennzahl rechnet je Standort.
4. **Dashboard:**
   - Standortwahl oben („Alle Standorte" plus jeder aktive Standort), gemerkt je Person im Browser
   - Abschnitt **„Auslastung"**: drei Zahlen (heute, diese Woche, nächste 4 Wochen), darunter je Standort eine Zeile, aufklappbar auf Behandler und Räume
   - Schwellen für die Färbung in `config/mrs.php` unter `dashboard.auslastung` (Vorschlag: unter 50 % „viel frei", ab 85 % „fast voll"), mit Fundstelle
5. **Übrige Kacheln nach WP-40:**
   - „Selbst gebucht" wird zu „Online oder vom Assistenten gebucht", mit Satz
   - „Templates" und „Assistenzläufe" wandern aus der Kontingentzeile in Worte („Nachrichtenvorlagen", „Antworten des Assistenten")
   - WP-39 (Erste Schritte) sitzt über allem, solange er nicht ausgeblendet ist
6. **Hinweis bei freier Kapazität:**
   - ist ein Standort in den nächsten 7 Tagen unter der unteren Schwelle, steht ein Satz mit zwei Links: „Warteliste ansehen" (`waitlist.manage`) und „Werbung starten" (WP-42, `campaigns.manage`)
   - jeder Link nur mit der Fähigkeit seiner Seite (`AppSidebar.vue`: ein Menüpunkt, der zu einer 403 führt, ist keiner)
7. **Wer was sieht:**
   - Inhaberin und Verwaltung: alles
   - Empfang: Auslastung ohne Werbehinweis
   - Behandlerin: nur die eigene Auslastung
   - Marketing: Auslastung je Standort, nicht je Behandler

## Abnahmekriterien

**Die Rechnung**

1. Ein Behandler mit 8 Stunden Arbeitszeit und zwei Terminen à 30 Minuten plus 10 Minuten Nachbereitung ergibt 80 / 480 Minuten = 16,7 %.
2. Eine ganztägige Abwesenheit setzt die verfügbaren Minuten des Tages auf 0 und lässt den Tag aus der Quote fallen. Er erscheint nicht als 0 % und nicht als Division durch null.
3. Eine Schließung des Standorts von 12 bis 14 Uhr senkt die verfügbaren Minuten um 120; ein Termin darin zählt nicht als belegt.
4. Ein abgesagter Termin zählt nicht, ein nicht erschienener schon.
5. Ein Termin außerhalb der Arbeitszeit (übersteuert) zählt nur mit dem Teil innerhalb der Arbeitszeit. Die Quote eines Tages ist nie über 100 %, außer bei Räumen.
6. Ein privater Kalenderblock von 60 Minuten erscheint als „extern belegt: 60", nicht in der Quote, und senkt „frei" um 60.
7. Ein Standort mit zwei Behandlern, einer voll (480 / 480) und einer leer (0 / 480), ergibt 50 %.
8. Hamburg und ein Standort in `America/New_York` am selben Kalendertag rechnen „heute" je in ihrer Zeitzone. Ein Termin um 23:30 New-York-Zeit gehört zum New-Yorker Tag, obwohl er in UTC am nächsten Tag liegt.
9. Um die Umstellung auf Sommerzeit rechnet ein Arbeitstag von 9 bis 17 Uhr 480 Minuten, nicht 420 oder 540.
10. Zwei Termine zur selben Zeit im selben Raum ergeben für den Raum mehr als 100 %, mit Hinweis „doppelt belegt".

**Die Seite**

11. „Alle Standorte" zeigt die Summe; die Wahl eines Standorts zeigt nur dessen Zahlen, auch in den übrigen Kacheln.
12. Die Behandlerin sieht nur ihre eigene Zeile, auch mit „Alle Standorte".
13. Der Hinweis bei freier Kapazität zeigt den Link „Werbung starten" nur mit `campaigns.manage`.
14. `Praxiskennzahlen` zählt „Termine heute" je Standort in dessen Zeitzone (Kriterium 8 gilt auch dort).
15. Auf dem Dashboard steht kein Wort aus `wortwahl.verboten` (WP-40).
16. Das Dashboard ruft kein Fremdsystem auf (Regel 4) und lädt mit einem gestörten Kalender wie bisher.

## Nicht in diesem Paket
- Umsatz je Standort, das kommt aus den Rechnungen (WP-48); Werbekennzahlen bleiben unter *Auswertung*.
- Prognosen und Empfehlungen jenseits des einen Hinweises.
- Auslastung im Backoffice.

## Fallstricke
- **Minuten summieren, nie Quoten mitteln.** Siehe Kriterium 7. Der häufigste Fehler in Auslastungsberichten.
- **Ein Tag ohne verfügbare Zeit ist kein 0-%-Tag.** Sonst drückt jeder Urlaub die Woche nach unten.
- **„Diese Woche" beginnt am Montag** in der Ortszeit des Standorts, wie die Wochenansicht im Kalender (WP-11).
- **Vorausschau ist Wahrheit von heute.** Die nächsten vier Wochen zeigen, was jetzt gebucht ist. Der Satz darunter sagt das, damit niemand eine leere übernächste Woche für ein Problem hält.
