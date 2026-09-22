# WP-28 · Insights & Aggregation

## Ziel
Die Praxis sieht, was ihre Werbung kostet und was sie bringt — Metas Zahlen,
täglich, je Kampagne.

## Vorher lesen
- `docs/integrationen/meta.md` — *Rate Limits und Fehler*, *API-Version*
- `docs/fachlogik/attribution.md` — besonders *Kennzahlen* und *Bekannte
  Grenzen*. **Die Kennzahlen dieses Pakets sind die Hälfte davon**, die ohne
  Attribution auskommt.
- `docs/datenmodell.md`, Abschnitt 7
- `docs/entscheidungen.md` — **P9** nach 12 Monaten Kampagnenebene, **P10**
  `attribution_touches` werden nicht mit aggregiert, **B2** kein Live-Aufruf
  im Request, **C7** Aufbewahrung
- `specs/WP-26-werbekonto-anbindung.md` — die Struktur, an der diese Zahlen
  hängen

## Voraussetzungen
WP-26.

## Die Linie, an der alles hängt

**Eine Zahl hat eine Quelle.**

Metas Insights-API beantwortet dieselbe Frage auf mehrere Weisen: man kann
einen Zeitraum als Summe abfragen oder als Tagesreihe, und man bekommt CTR,
CPC und CPM gleich mitgeliefert. Beides zu speichern heißt, zwei Zahlen für
dieselbe Aussage zu führen — und die weichen voneinander ab, sobald Meta
rundet oder ein Tag nachträglich korrigiert wird.

Gespeichert werden deshalb **ausschließlich Tageszeilen mit den vier
Grundwerten**: Ausgaben, Impressionen, Klicks, Link-Klicks — dazu die von
Meta gemeldeten Leads. Jede Summe und jede Quote wird daraus gerechnet, nie
abgefragt.

Das ist dasselbe Muster wie bei der Nutzungsübersicht in WP-06 und beim
Agenten-Kontingent: **nie zwei Orte für dieselbe Zahl.**

## Vier Eigenheiten, an denen dieses Paket hängt

### Gestern ändert sich noch

Metas Zuordnungsfenster wirkt rückwirkend: die Zahlen eines Tages bewegen
sich bis zu 28 Tage lang. Ein Abgleich, der nur den Vortag holt, friert
falsche Werte ein — und niemand bemerkt es, weil die Zahl ja dasteht.

Gelesen wird deshalb **ein nachlaufendes Fenster** und nicht ein Tag; jede
Zeile wird überschrieben, nicht ergänzt.

### Geld kommt anders als Budget

Budgets liefert Meta in der kleinsten Einheit (`"2500"` = 25,00 €),
**Ausgaben als Dezimalzeichenkette** (`"25.43"`). Zwei Konventionen in einer
API. Wer `spend` wie ein Budget liest, zeigt hundertfache Kosten; wer es als
Gleitkommazahl führt, verliert Cent.

Gespeichert wird als Ganzzahl in der kleinsten Einheit der Kontowährung, und
die Währung steht am Werbekonto.

### Ein Tag ist ein Etikett, keine Uhrzeit

Insights-Tage laufen in der **Zeitzone des Werbekontos**, nicht in UTC. Die
Zeile trägt deshalb ein Datum und keinen Zeitstempel. Die Umrechnung, die
WP-26 bei Laufzeiten braucht, wäre hier falsch.

### Reichweite lässt sich nicht addieren

Reichweite ist die Zahl **verschiedener Menschen**. Die Tageswerte einer
Woche zu addieren zählt dieselbe Person siebenmal.

Deshalb wird sie **gar nicht gespeichert**. Eine Zahl, die nicht summierbar
ist, aber neben summierbaren in derselben Tabelle steht, wird irgendwann
summiert — und dann steht eine falsche Zahl im Dashboard einer Praxis, die
ihre Werbebudgets danach richtet.

## Schritte

1. `ad_insights`: Mandant, Werbekonto, `level`, `external_id`, `stat_date`,
   `spend_minor`, `impressions`, `clicks`, `link_clicks`, `leads`,
   `synced_at`. Eindeutig über (Mandant, Ebene, Kennung, Datum).
2. `App\Werbung\Meta\Insightsleser` — Tagesreihen je Ebene, über den
   vorhandenen `Graphleser`.
3. `App\Werbung\Kennzahlenabgleich` — nachlaufendes Fenster, überschreibend.
4. `App\Werbung\Kennzahlen` — Summen und Quoten über einen Zeitraum, je
   Kampagne und gesamt. **Die einzige Stelle, die rechnet.**
5. `Jobs\WerbezahlenAbgleichen`, Queue `default`, je Werbekonto.
6. `mrs:werbung-zahlen` täglich; der Knopf *Jetzt abgleichen* aus WP-26 holt
   beides.
7. Oberfläche: Zeitraumwahl, Summenzeile, Zahlen je Kampagne, Hinweis auf
   die bekannten Grenzen.
8. **P9** als Aufbewahrungsgegenstand: nach 12 Monaten fallen Anzeigengruppen-
   und Anzeigenzeilen weg, die Kampagnenebene bleibt. Läuft über die
   vorhandene Mechanik aus WP-18 — mit Vorschau, wie alles dort.

## Abnahmekriterien

**Lesen**

1. Ein Abgleich legt Tageszeilen je Ebene an.
2. Ein zweiter Lauf legt nichts doppelt an, sondern überschreibt.
3. Geänderte Zahlen eines zurückliegenden Tages kommen an.
4. Das nachlaufende Fenster umfasst den konfigurierten Zeitraum, nicht nur
   den Vortag.
5. Ausgaben werden als Dezimalzeichenkette gelesen und als Ganzzahl in der
   kleinsten Einheit abgelegt.
6. Ein Tag ohne Ausgaben erzeugt eine Zeile mit Null, keine fehlende Zeile.
7. Ein Rate-Limit wird wiederholt, ein ungültiges Token nicht.
8. Der Abgleich läuft nie im Request-Zyklus.

**Rechnen**

9. Die Summe eines Zeitraums ist die Summe der Tageszeilen.
10. CTR, CPC und CPM werden gerechnet, nicht gespeichert.
11. Ein Zeitraum ohne Impressionen liefert keine Division durch Null,
    sondern „—".
12. Die Summe je Kampagne stimmt mit der Gesamtsumme überein.

**Regeln**

13. Zwei Mandanten sehen ausschließlich ihre eigenen Zahlen.
14. Die Seite lädt bei einer Meta-Störung mit dem letzten Stand und
    sichtbarem Hinweis.
15. **Kein schreibender Graph-Aufruf** — die Regel aus WP-26 gilt weiter.
16. Nach zwölf Monaten gibt es keine Zeilen unterhalb der Kampagnenebene
    mehr, die Kampagnenzeilen bleiben.

## Nicht in diesem Paket

- **Cost per Lead, CAC, ROAS, Show-Rate.** Sie brauchen die Zuordnung aus
  WP-32. Hier stehen Metas eigene Zahlen, nicht die des Produkts.
- **Conversions API.** WP-32.
- **Aufschlüsselungen nach Alter, Geschlecht, Platzierung.** Reizvoll und
  hier nicht gebraucht; jede zusätzliche Aufschlüsselung vervielfacht die
  Zeilen.
- **Zielgruppenmerkmale.** Wie in WP-26: im Umfeld einer ästhetischen Praxis
  gesundheitsnah, und für nichts gebraucht.

## Fallstricke

- **Die Tagesreihe kostet Anfragen.** Drei Ebenen mal Fenster mal Mandanten.
  Ein Job je Werbekonto, nicht einer über alle.
- **`time_increment=1` ist Pflicht.** Ohne ihn liefert Meta eine Summe über
  den Zeitraum, und die lässt sich nicht mehr auf Tage verteilen.
- **Metas Ergebniszählung ist nicht unsere.** Was Meta als Lead meldet, ist
  ein Formularabschluss bei Meta — nicht ein Lead im Produkt. Beide Zahlen
  nebeneinander zu zeigen, ohne den Unterschied zu benennen, erzeugt genau
  die Diskussion, die WP-32 gewinnen soll.
- **Eine Kampagne ohne Zeilen ist nicht dasselbe wie eine mit Nullen.**
  Pausiert seit Wochen heißt: keine Zeilen. Das Dashboard muss beides
  unterscheiden können.

## Stand

Die 16 Abnahmekriterien laufen: `tests/Feature/Werbung/KennzahlenTest.php`
(**18 Tests**). Gesamtstand 866 Tests, 3026 Zusicherungen. PHPStan Stufe 8
sauber, `vue-tsc` sauber.

Neu: `ad_insights`, `AdInsight`, `InsightLevel`, `App\Werbung`
(`Meta\Insightsleser`, `Kennzahlenabgleich`, `Kennzahlen`, `Kennzahlensatz`,
`Tageszahl`, `Kennzahlenbilanz`), `WerbezahlenAbgleichen`,
`mrs:werbung-zahlen` (täglich 05:45) und der Kennzahlenteil der Seite
*Werbung*: Zeitraumwahl, Summenkacheln, Tagesverlauf, Zahlen je Kampagne.

`RetentionSubject::AdInsightDetail` setzt **P9** über die vorhandene
Aufbewahrung aus WP-18 durch — mit Vorschau, wie alles dort.

Im Browser nachgesehen: 30 Tage Demo-Zahlen über drei Kampagnen. Die Summe
373,16 € des 7-Tage-Fensters ist genau die Summe der beiden Kampagnenzeilen
(153,96 € + 219,20 €); die pausierte Kampagne steht auf „keine Auslieferung"
statt auf Null.

## Was das Bauen zutage gefördert hat

**„Verbunden" stand neben „getrennt".** Ein getrenntes Werbekonto behält
seinen letzten Zustand — und die Seite zeigte beide Abzeichen nebeneinander.
Aufgefallen ist das erst mit echten Daten im Browser, nicht im Test: die
Zusicherungen prüften die Werte, nicht ihre Kombination.

**Ein zweites `Http::fake()` auf dasselbe Muster ersetzt das erste nicht.**
Derselbe Fallstrick wie in WP-20a, und er kostet wieder eine Viertelstunde:
zwei Läufe in einem Test brauchen **eine** Sequenz, nicht zwei Fakes. Der
Fehler sieht aus wie ein Fehler im Code („response sequence is empty") und
steht in Wirklichkeit im Test.

**Runden gehört ans Ende.** `(int) (0.07 * 100)` ist 6, nicht 7 — 0,07 lässt
sich binär nicht darstellen. Bei Tagesausgaben unter einem Euro hätte das
still jeden Cent verschluckt, und in der Summe eines Monats wäre nichts mehr
nachzurechnen gewesen. Eigener Testfall.

**Reichweite wurde weggelassen, nicht vergessen.** Sie zählt verschiedene
Menschen und lässt sich nicht über Tage addieren. Eine nicht summierbare Zahl
neben summierbaren in derselben Tabelle wird irgendwann summiert — und dann
richtet eine Praxis ihr Budget danach.

**Zwei Zahlen heißen fast gleich.** „Kosten je Ergebnis" hier und „Cost per
Lead" in `docs/fachlogik/attribution.md` sind dieselbe Formel über
verschiedene Grundgesamtheiten: Metas Formularabschlüsse gegen Anfragen, die
bei der Praxis ankommen. Beide gleich zu benennen wäre der schnellste Weg,
Vertrauen in die Zahlen zu verlieren — der Unterschied steht jetzt in der
Oberfläche, nicht nur im Code.

## Offen

- **Der Verlauf ist ein Balkenbild, kein Diagramm.** Für „lief die Woche
  besser als die letzte" reicht es. Sobald zwei Reihen verglichen werden
  sollen, braucht es mehr.
- **Aufschlüsselungen** nach Alter, Geschlecht und Platzierung. Reizvoll, und
  jede vervielfacht die Zeilen.
- **Ein Zeitraum, der weiter zurückreicht als das Abgleichfenster**, zeigt
  nur, was schon geholt wurde. Ein Erstabgleich holt 28 Tage, nicht die
  Historie — wer ältere Zahlen braucht, braucht einen Nachlauf.
