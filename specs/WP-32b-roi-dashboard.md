# WP-32b · Kennzahlen, ROI-Dashboard und Conversions API

> Zweiter Teil von WP-32. **WP-32a** hat die Kette erfasst; hier wird darauf
> gerechnet — und zum ersten Mal geht etwas an Meta zurück.

## Ziel
Die Zahl sichtbar machen, die das Abo rechtfertigt: was die Werbung kostet
und was sie einbringt.

## Vorher lesen
- **`docs/fachlogik/attribution.md` vollständig.** Verbindlich; besonders
  *Kennzahlen*, *Conversions API* und *Bekannte Grenzen*.
- `CLAUDE.md`, **Regel 2** — hier wird sie scharf
- `specs/WP-32a-attribution-erfassung.md`, `specs/WP-28-insights-aggregation.md`
- `docs/entscheidungen.md` — **C8** keine Custom Audiences, **C9**, **D13**,
  **P9**

## Voraussetzungen
WP-32a, WP-28.

## Die Linie, an der alles hängt

**Nichts über eine Behandlung geht an Meta. In keinem Feld.**

Die Conversions API ist die erste Stelle des Produkts, die Ereignisse zu
personenbezogenen Daten an Meta schickt. Erlaubt sind drei Ereignisnamen —
`Lead`, `Schedule`, `Contact` — und ein fest umrissener Satz Nutzerdaten,
gehasht. Verboten sind `treatment_id`, Behandlungsname, Kategorie,
Katalogbezeichnung, `content_name`, `content_category`, `content_ids` und jede
Wertübermittlung, aus der sich eine Behandlung ableiten ließe.

Abgesichert wird das nicht durch Sorgfalt, sondern durch einen Test, der
**jeden ausgehenden Payload** gegen alle aktiven Katalognamen hält
(`docs/fachlogik/attribution.md`, Testfall 7).

## Der Schlüssel ist nicht der Name

Der `attribution_snapshot` aus WP-32a ist verschlüsselt, weil er den
Kampagnennamen trägt. Damit lässt sich nicht gruppieren: jede Auswertung
müsste jeden Termin entschlüsseln.

Deshalb trennt dieses Paket beides:

| Feld | Art | Wofür |
|---|---|---|
| `attribution_campaign_id` | Klartext, indiziert | Gruppieren und Verknüpfen |
| `attribution_snapshot` | verschlüsselt | Lesen durch Menschen |

**Die Kennung ist eine Ziffernfolge ohne Aussage.** Wer sie zu einem Namen
auflösen will, braucht unsere `ad_campaigns` — und dort liegt der Name
verschlüsselt. Der Schlüssel darf offen liegen, die Aussage nicht.

## Ausgaben lassen sich nur nach Kampagne aufschlüsseln

`attribution.md` verlangt Aufschlüsselung nach Kampagne, Behandlung,
Behandler, Standort und Zeitraum. Das gilt für die Konversionsseite.

**Für die Kostenseite gilt es nicht.** Meta rechnet je Kampagne ab, nicht je
Behandler. Eine Ansicht nach Behandler kann Leads, Termine und Umsatz zeigen —
Cost per Lead, CAC und ROAS stehen dort auf „—", und zwar sichtbar. Eine
gerechnete Kostenverteilung wäre erfunden, und erfundene Zahlen sind
schlimmer als fehlende.

## Zwei Zahlen heißen fast gleich

WP-28 zeigt „Kosten je Ergebnis" — Metas eigene Zählung, ein Formularabschluss
bei Meta. Hier entsteht „Cost per Lead" — eine Anfrage, die bei der Praxis
ankommt. Dieselbe Formel, andere Grundgesamtheit. **Beide erscheinen
nebeneinander und benennen den Unterschied**; das ist der eine Ort, an dem
sich das Produkt gegen Metas Zahlen behaupten muss.

## Gewonnen heißt erschienen

`Lead::beiStatus` hält es schon fest, und die Auswertung folgt dem: ein
Termin, den niemand wahrnimmt, zählt nicht als Abschluss. Sonst misst der
ROAS Absichten statt Umsatz.

**Und der Umsatz ist eine Schätzung.** `treatments.avg_revenue_cents` ist ein
Durchschnitt, kein abgerechneter Betrag. Das Produkt nennt ihn durchgängig
„zugeordneter Schätzwert" — eine Zahl, die genauer aussieht, als sie ist,
kostet beim ersten Nachrechnen das Vertrauen in alle anderen.

## Schritte

1. `appointments.attribution_campaign_id` — Klartext neben dem Snapshot.
2. `App\Attribution\Auswertung` — die Kennzahlen aus
   `docs/fachlogik/attribution.md`, je Zeitraum, Modell und Gruppierung.
3. Seite *Auswertung*: Zeitraum, Modell, Aufschlüsselung, bekannte Grenzen.
4. `App\Attribution\Meta\Konversionsereignis` — der Payload, abschließend.
5. `App\Attribution\Meta\Conversionsversand` + Auftrag, Queue `default`.
6. `event_id` im Pixel und im Serverereignis — dieselbe.
7. Der ausführbare Regel-2-Test über **jeden** ausgehenden Payload.

## Abnahmekriterien

**Kennzahlen**

1. Leads, gebuchte Beratungen, Erschienene und Abschlüsse zählen nach den
   Definitionen der Spezifikation.
2. Show-Rate und No-Show-Quote rechnen richtig und liefern bei leerem Nenner
   „—", nicht 0.
3. Zugeordneter Umsatz summiert `avg_revenue_cents` der gewonnenen Leads.
4. Cost per Lead, Cost per Consult, CAC und ROAS stimmen mit einer von Hand
   nachgerechneten Testkampagne überein (**Testfall 10**).
5. Ein Wechsel des Modells verändert die Zuordnung (First gegen
   Last-Non-Direct).
6. Speed-to-Lead ist der Median, nicht der Mittelwert.
7. Bei Aufschlüsselung nach Behandler stehen die Kostenkennzahlen auf „—".
8. Ein Lead ohne Kampagne erscheint als „Quelle unbekannt".

**Conversions API**

9. Es gehen ausschließlich `Lead`, `Schedule` und `Contact` hinaus.
10. `event_id` ist zwischen Pixel und Serverereignis identisch
    (**Testfall 8**).
11. E-Mail und Telefonnummer gehen ausschließlich gehasht hinaus, normalisiert
    vor dem Hashen.
12. **Kein ausgehender Payload enthält einen Katalognamen** — geprüft gegen
    alle aktiven Behandlungen (**Testfall 7**).
13. Kein `custom_data` mit Behandlungsbezug, keine Wertübermittlung.
14. Der Versand läuft über eine Queue, nie im Request-Zyklus.
15. Ohne Einwilligung geht **kein** Ereignis hinaus.

**Regeln**

16. Zwei Mandanten sehen ausschließlich ihre eigenen Zahlen.

## Nicht in diesem Paket

- **Custom Audiences und Lookalikes.** C8, dauerhaft.
- **Abgerechneter Umsatz.** Das Produkt kennt Durchschnittswerte aus dem
  Katalog, keine Rechnungen.
- **Aufschlüsselung nach Anzeigengruppe und Anzeige jenseits von zwölf
  Monaten.** P9.
- **Ein Export.** Nachvollziehbar muss die Zahl im Produkt sein, nicht in
  einer Tabelle daneben.

## Fallstricke

- **Ein Payload, der beim Hinausgehen zusammengebaut wird**, entzieht sich
  dem Test. Er entsteht an einer Stelle, und der Test prüft genau diese.
- **Ohne `event_id` zählt Meta doppelt** — Pixel und Server melden dasselbe
  Ereignis.
- **Hashen ohne Normalisieren ist kein Hashen**: Großschreibung und
  Leerzeichen ergeben einen anderen Wert, und Meta ordnet nichts zu.
- **Der ROAS einer Kampagne ohne Ausgaben** ist keine Division durch null,
  sondern keine Aussage.

## Stand

Die 16 Abnahmekriterien laufen:
`tests/Feature/Attribution/AuswertungTest.php` (**17 Tests**), dazu die
`event_id`-Probe in `tests/Feature/Buchung/BuchungsseiteTest.php` und der
geschärfte Pixeltest. Gesamtstand 947 Tests, 3358 Zusicherungen. PHPStan
Stufe 8 sauber, `vue-tsc` sauber, 36 Seiten im Manifest.

Damit sind die Testfälle **7, 8 und 10** aus `docs/fachlogik/attribution.md`
abgedeckt — und mit WP-32a alle zehn.

Neu: `appointments.attribution_campaign_id` (Klartext neben dem
verschlüsselten Snapshot), `Aufschluesselung`, `App\Attribution\Auswertung`
und `Auswertungszeile`, `App\Attribution\Meta\{Konversionsereignis,
Conversionsversand}`, `KonversionMelden`, `routes/auswertung.php` und die
Seite *Auswertung* mit eigenem Menüpunkt hinter `insights.view`.

Im Browser durchgespielt: 15 Demo-Buchungen über zwei Kampagnen, dazu die
Bestandsdaten. 27 Anfragen, davon 15 zugeordnet; 1.399,47 € Ausgaben, ROAS
2,66×; „Quelle unbekannt" ohne Kostenkennzahlen.

## Was das Bauen zutage gefördert hat

**Zwei Zahlen waren falsch, und beide in die schmeichelnde Richtung.** Beide
sind erst mit echten Daten im Browser aufgefallen, nicht im Test:

*Erstens:* Eine Kampagnenzeile ohne Insights-Zeilen zeigte „0,00 €" und damit
„0,00 € je Anfrage" — als wären diese Anfragen umsonst gekommen. Keine Zeile
bei Meta heißt **unbekannt**, nicht null Euro; dieselbe Unterscheidung wie in
WP-28 zwischen „keine Auslieferung" und „Nullen".

*Zweitens:* Die Gesamtsumme teilte die Ausgaben durch **alle** Anfragen,
einschließlich derer ohne Quelle — 51,83 € statt 93,30 €. Die Spezifikation
sagt es genau: „Leads: Anzahl `leads` **mit zugeordneter Kampagne** im
Zeitraum". Wer die anderen mitzählt, druckt die Kosten je Anfrage künstlich —
und zwar in die Richtung, in der eine Praxis eine Kampagne weiterlaufen lässt,
die sich nicht rechnet.

**Der Schlüssel darf offen liegen, die Aussage nicht.** Der
`attribution_snapshot` aus WP-32a ist verschlüsselt und damit nicht
gruppierbar. Die Kampagnenkennung steht jetzt im Klartext daneben: eine
Ziffernfolge ohne Aussage, die sich nur über unsere eigene — verschlüsselte —
`ad_campaigns` zu einem Namen auflösen lässt.

**Die Regel-2-Prüfung steht im Produktionsweg, nicht nur im Test.** Vor jedem
Absenden läuft der fertige Payload gegen alle aktiven Katalognamen. Ein Test
schützt vor dem, woran jemand gedacht hat; diese Prüfung auch vor dem Feld,
das eine spätere Fassung hinzufügt. Die Gegenprobe steht daneben: ein
Katalogname im Payload hält den Versand an.

**Es gibt kein `custom_data`** — nicht weil gerade nichts hineingehört,
sondern damit nie etwas hineinkommt. Ein leeres Feld füllt sich. Der Test
hält die Feldliste des Payloads abschließend fest.

**Das Pixel bekam ein viertes Argument.** `Lead` trägt jetzt eine `eventID`,
sonst zählt Meta doppelt. Das dritte Argument bleibt leer — dort stünden
`content_name`, `content_category`, `content_ids`. Der Pixeltest erlaubt
seither genau diese eine Form und keine andere.

## Offen

- **Gegen die echte Conversions API geprüft ist nichts.** Es fehlt
  `META_CAPI_TOKEN`, und ohne Zugang sendet das Produkt bewusst gar nicht —
  der Normalfall vor dem App Review.
- **`Schedule` und `Contact`** sind erlaubt, werden aber noch nirgends
  ausgelöst. `Lead` bei der Buchung genügt für den Anfang.
- **Die Modellumschaltung rechnet je Termin neu** und lädt dafür die
  Berührungen. Bei einigen hundert Terminen im Zeitraum ist das in Ordnung;
  bei einigen tausend gehört es in eine Vorberechnung.
- **`avg_revenue_cents` bleibt eine Schätzung.** Solange das Produkt keine
  Rechnungen kennt, ist der ROAS ein Näherungswert — im Dashboard so benannt.
