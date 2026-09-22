# WP-32a · Attribution: Erfassung und Zuordnung

> **WP-32 ist zwei Sitzungen.** Dieses Paket baut die Kette; die Zahlen darauf
> — Kennzahlen, ROI-Dashboard, Conversions API — sind **WP-32b**. Ohne
> Erfassung gäbe es dort nichts zu rechnen, deshalb diese Reihenfolge.

## Ziel
Die Kette von der Anzeige bis zum Termin lückenlos festhalten — und dabei
weniger speichern, als man könnte.

## Vorher lesen
- **`docs/fachlogik/attribution.md` vollständig.** Verbindlich; die
  Testfalliste ist der Auftrag.
- `docs/entscheidungen.md` — **D13** `attribution_snapshot` einfrieren,
  **P10** Touches werden nicht mit aggregiert, **C7** Aufbewahrung
- `CLAUDE.md`, Regeln 2 und 3
- `specs/WP-26-werbekonto-anbindung.md` — die Kennungen, auf die die Touches
  zeigen

## Voraussetzungen
WP-12 (Buchungsseite), WP-17 (Leads), WP-26 (Kampagnenstruktur).

## Die Linie, an der alles hängt

**Ohne Einwilligung keine Messung.**

Ein Wiedererkennungs-Cookie mit 180 Tagen Laufzeit ist nicht „technisch
notwendig". § 25 TTDSG verlangt dafür eine Einwilligung — und zwar bevor er
gesetzt wird, nicht während.

Dasselbe gilt für das Meta-Pixel, das seit WP-19 auf der Buchungsseite läuft:
es feuert dort bisher **ungefragt**. Das wird hier behoben, nicht nebenbei,
sondern als Teil der Aufgabe. Eine Praxis, die für Werbung wirbt, darf nicht
diejenige sein, die deswegen abgemahnt wird.

Was ohne Einwilligung geschieht, steht in `docs/fachlogik/attribution.md`
unter *Bekannte Grenzen* und gehört ins Dashboard: der Lead entsteht
trotzdem, nur ohne Kampagnenbezug — „Quelle unbekannt", nicht „Direktzugriff".

## Weniger speichern, als die Spezifikation nennt

`attribution.md` nennt `landing_url` und `referrer`. Beides vollständig zu
speichern wäre hier ein Fehler:

Die Buchungsseite kann eine Behandlung in der Adresse tragen — und sobald ein
Touch rückwirkend mit `contact_id` verknüpft ist, stünde ein Behandlungsname
**unverschlüsselt neben einem Kontakt**. Das ist Regel 3, und es ist derselbe
Fund wie bei den Kampagnennamen in WP-26.

Gespeichert wird deshalb:

| statt | dies | warum |
|---|---|---|
| `landing_url` | `landing_path` ohne Abfrageteil | der Abfrageteil trägt, was jemand angehängt hat |
| `referrer` | `referrer_host` | woher jemand kam, ist eine Quelle; die genaue Seite ist eine Aussage über ihn |

Die Parameter, die gebraucht werden — `fbclid`, `utm_*` —, stehen ohnehin in
eigenen Spalten. **Was nicht gebraucht wird, wird nicht gespeichert.**

Das ist eine Abweichung von einer verbindlichen Spezifikation und gehört
bestätigt.

## Das Modell wird nicht im Schema festgeschrieben

Alle Touches werden gespeichert; First, Last, Last-Non-Direct und Linear
werden **zur Abfragezeit** gerechnet. Eine Zuordnung, die beim Schreiben
entsteht, lässt sich später nicht anders ansehen — und genau das will eine
Praxis, die wissen möchte, ob ihre Anzeige den ersten oder den letzten Anstoß
gab.

**Standard ist Last Non-Direct.** Ein Direktaufruf ist keine Quelle, sondern
das Fehlen einer.

## Das Rückblickfenster ist eine Aussage, keine Einstellung

28 Tage, konfigurierbar. Der Entscheidungsweg bei ästhetischen Eingriffen ist
lang; mit einem kürzeren Fenster wird systematisch zu wenig zugeordnet, und
die Praxis hält ihre Werbung für schlechter, als sie ist. **Der Wert gehört
sichtbar ins Dashboard**, nicht in eine Konfigurationsdatei allein.

## Schritte

1. Einwilligung auf der Buchungsseite: schlichte Abfrage, Entscheidung in
   einem eigenen Cookie, ohne Einwilligung **kein** Pixel und **kein**
   Besucher-Cookie.
2. `App\Attribution\Besucherkennung` — First-Party-Cookie, 180 Tage,
   Zufallswert, `httpOnly`.
3. `attribution_touches` und `App\Attribution\Beruehrungen` — schreibt einen
   Touch je Aufruf der Buchungsseite.
4. Rückwirkende Verknüpfung: entsteht ein Lead, bekommen **alle** Touches
   dieses Besuchers `contact_id` und `lead_id`.
5. `App\Attribution\Zuordnung` — die vier Modelle, zur Abfragezeit, mit
   Rückblickfenster.
6. `attribution_snapshot` am Termin einfrieren (D13).
7. **Quelle als Pflichtfeld** beim internen Anlegen eines Termins.
8. Aufbewahrung: Touches ohne Verknüpfung fallen weg (C7).

## Abnahmekriterien

**Einwilligung**

1. Ohne Entscheidung wird weder ein Besucher-Cookie gesetzt noch ein Touch
   geschrieben.
2. Ohne Einwilligung feuert das Pixel nicht.
3. Nach der Ablehnung bleibt die Seite vollständig benutzbar.
4. Nach der Einwilligung entsteht genau ein Besucher-Cookie mit 180 Tagen.

**Erfassung**

5. Ein Aufruf mit `fbclid` schreibt einen Touch mit diesem Wert.
6. UTM-Parameter landen in ihren Spalten.
7. **Der Abfrageteil der Adresse wird nicht gespeichert** — geprüft mit einem
   Katalognamen in der URL.
8. Vom Verweis bleibt nur der Host.
9. Zwei Aufrufe desselben Besuchers ergeben zwei Touches mit derselben
   `visitor_id`.

**Zuordnung**

10. Klick mit `fbclid` → Buchung → der Termin trägt die Kampagne im Snapshot
    (Testfall 1).
11. Eine Umbenennung der Kampagne bei Meta verändert den Snapshot nicht
    (Testfall 2).
12. Mehrere Touches ergeben bei First und Last verschiedene Zuordnungen
    (Testfall 3).
13. Ein Direktaufruf als letzter Touch wird bei Last-Non-Direct übersprungen
    (Testfall 4).
14. Ein Touch außerhalb des Rückblickfensters wird nicht zugeordnet
    (Testfall 5).
15. Ein Lead ohne Besucherzuordnung erscheint als „Quelle unbekannt", nicht
    als Direktzugriff (Testfall 9).

**Quelle**

16. Ein manuell angelegter Termin ohne Quelle ist nicht speicherbar
    (Testfall 6).

**Regeln**

17. Zwei Mandanten sehen ausschließlich ihre eigenen Touches.
18. Kein Katalogname steht in einer Touch-Zeile.

## Nicht in diesem Paket

- **Kennzahlen, ROAS, Dashboard.** WP-32b.
- **Conversions API.** WP-32b — dort wird Regel 2 wirklich scharf.
- **Das Website-Snippet des Kunden.** Die eigene Buchungsseite zuerst; ein
  Snippet auf einer fremden Website ist ein eigenes Thema mitsamt deren
  Einwilligungsbanner.
- **Auflösung von `fbclid` zu Kampagne, Anzeigengruppe, Anzeige.** Der Weg
  dorthin führt über Metas Ereignisdaten und ist ohne App Review nicht
  prüfbar; bis dahin kommt die Zuordnung aus den UTM-Parametern, die WP-27
  selbst setzt.

## Fallstricke

- **Ein Cookie, das vor der Einwilligung gesetzt wird**, ist der Fehler, den
  dieses Paket vermeiden soll.
- **Ein Touch ohne Mandanten** ist nicht zuzuordnen: die Buchungsseite löst
  den Mandanten aus dem Slug auf, und das muss vor dem Schreiben geschehen.
- **`visitor_id` ist keine Personenkennung** — bis sie mit `contact_id`
  verknüpft wird. Ab da gilt für die Zeile, was für einen Kontakt gilt.
- **Der Snapshot ist eine Kopie, kein Verweis.** Ein Verweis würde mit der
  Kampagne umbenannt, und die Auswertung eines alten Termins wäre nach einem
  halben Jahr wertlos (D13).
- **Zeit:** Touches liegen in UTC, das Rückblickfenster rechnet in Tagen.

## Stand

Die 18 Abnahmekriterien laufen: `tests/Feature/Attribution/AttributionTest.php`
(**18 Tests**), dazu die Kette von Ende zu Ende in
`tests/Feature/Buchung/BuchungsseiteTest.php` und die Pixel-Schranke in
`tests/Feature/Meta/PixelTest.php`. Gesamtstand 928 Tests, 3275 Zusicherungen.
PHPStan Stufe 8 sauber, `vue-tsc` sauber.

Abgedeckt sind die Testfälle **1 bis 6 und 9** aus
`docs/fachlogik/attribution.md`. Die Fälle 7, 8 und 10 (Conversions API,
`event_id`, ROAS) gehören zu WP-32b.

Neu: `attribution_touches`, `AttributionTouch`, `AttributionModel`,
`appointments.attribution_snapshot` (verschlüsselt),
`App\Attribution\{Besucherkennung, Beruehrungen, Zuordnung}`,
`RetentionSubject::AttributionTouch`, die Einwilligungsabfrage auf der
Buchungsseite samt Route, und `quelle` als Pflichtfeld beim internen
Terminanlegen.

Im Browser durchgespielt: Aufruf mit `fbclid`, `utm_source` und
`mrs_campaign` — **vor** der Einwilligung null Touches, nach dem Klick auf
*Einverstanden* Zeilen mit Klick-ID, Quelle, Kampagnenkennung, Pfad ohne
Abfrageteil und Verweis-Host.

## Was das Bauen zutage gefördert hat

**Das Pixel feuerte ungefragt.** Seit WP-19 lud es auf der Buchungsseite,
sobald eine Pixel-ID hinterlegt war — ohne jede Einwilligung. Das ist beim
Bauen dieses Pakets aufgefallen, weil dieselbe Frage sich für das
Besucher-Cookie stellte. Jetzt entscheidet der Server: ohne Einwilligung
kommt `pixelId` gar nicht erst an der Seite an.

**Der eigene Host ist keine Quelle.** Im Durchlauf gegen die
Entwicklungsumgebung trug der zweite Aufruf `mrs-beauty.test` als Verweis —
und hätte damit als Touch **mit** Quelle gegolten. Last-Non-Direct hätte dann
nie einen Direktaufruf übersprungen, weil es keinen mehr gegeben hätte. Das
Modell wäre still zu Last-Touch geworden, und niemand hätte es bemerkt.

**Weniger speichern war die bessere Antwort als verschlüsseln.** Die
Spezifikation nennt `landing_url` und `referrer`. Beides vollständig zu
speichern hieße, eine Behandlungsbezeichnung aus der Adresse neben einen
Kontakt zu setzen. Verschlüsseln hätte das Problem versteckt; der Pfad ohne
Abfrageteil und der Host ohne Seite lösen es. **Das ist eine Abweichung von
einer verbindlichen Spezifikation und gehört bestätigt.**

**Pest teilt Hilfsfunktionen über alle Dateien — schon wieder.** Die
Ende-zu-Ende-Tests stehen deshalb dort, wo `praxisMitBuchungsseite()` lebt,
statt eine zweite Fassung davon anzulegen.

**Eine Zeitreise im `beforeEach` kollidiert mit einem Szenario, das seine
eigene Zeit mitbringt.** Der Vorschlag lag jenseits des Buchungshorizonts,
und die Fehlermeldung sagte etwas über 90 Tage, nicht über den Test.

## Offen

- **WP-32b:** Kennzahlen, ROI-Dashboard, Conversions API — dort wird Regel 2
  wirklich scharf.
- **Die Abweichung bei `landing_path` und `referrer_host`** gegenüber
  `docs/fachlogik/attribution.md`. Bestätigung durch den
  Produktverantwortlichen.
- **Auflösung von `fbclid` zu Kampagne, Anzeigengruppe, Anzeige.** Bis dahin
  kommt die Zuordnung aus den Parametern, die WP-27 selbst setzt.
- **Das Website-Snippet des Kunden.** Ein Snippet auf einer fremden Website
  bringt deren Einwilligungsbanner mit — ein eigenes Thema.
- **Die Einwilligung ist eine Ja-Nein-Frage**, keine Kategorienauswahl. Für
  ein Produkt mit genau einem Messzweck ist das ehrlicher als drei Schalter;
  sobald ein zweiter dazukommt, reicht es nicht mehr.
