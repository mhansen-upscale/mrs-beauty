# WP-31b · Anzeigenformate

> Nachtrag zu WP-31 und WP-27b. Bis hierher entstand jede Anzeigengrafik
> **quadratisch** — und genau so ging sie zu Meta, in jede Platzierung. In
> Stories und Reels erscheint ein Quadrat als Streifen in der Bildmitte, im
> Feed verschenkt es die Fläche, die ein Hochformat bekommt.

## Ziel
Jede Anzeige entsteht in den gängigen Meta-Formaten, und jede Platzierung
bekommt das Format, das zu ihr passt.

## Vorher lesen
- `specs/WP-31-anzeigenvorschlaege.md` — woher die Grafik kommt, und warum
  sie nie Grün bekommt
- `specs/WP-27b-anzeigen-schalten.md` — der Weg zu Meta
- `docs/entscheidungen.md` — **B13** Bildkontingent, **C9**, **C10**,
  **C13** (dieses Paket)
- `CLAUDE.md`, Regeln 2 und 4

## Voraussetzungen
WP-31, WP-27b.

## Die Formate

| Format | Seitenverhältnis | Wo Meta es zeigt |
|---|---|---|
| Quadratisch | 1:1 | Auffangformat: rechte Spalte, Marketplace, Suche und jede Platzierung ohne eigene Regel |
| Hochformat | 4:5 | Feed auf Facebook, Feed, Explore und Profil auf Instagram |
| Stories | 9:16 | Stories auf Facebook, Instagram und im Messenger |

Fundstellen: Metas Leitfaden für Bildanzeigen (4:5 für den Feed, 9:16 für
Stories und Reels, 1:1 als universelles Format) und die Entwicklerseite
*Placement Asset Customization* (Stand 28.06.2026) für die Namen der
Platzierungen.

## Die Linie, an der alles hängt

**Drei Grafiken, eine Anzeige.** Das Bildmodell entwirft jedes Format
eigens, statt ein Quadrat zu beschneiden: die Schrift steht im Bild, und ein
Zuschnitt schnitte sie ab. Die zweite Bildpipeline im PHP-Prozess ist
seit WP-31 verworfen, und dabei bleibt es.

**Jedes Format trägt seine eigene Schrift — und jede kann danebengehen.**
Das Bildmodell setzt die Überschrift dreimal, nicht einmal. Die Regel aus
WP-30 gilt deshalb für alle drei: ein Bild bekommt nie Grün, und wer
freigibt, hat alle drei gelesen.

**Geschaltet wird nur ein vollständiger Satz.** Eine Anzeige mit zwei von
drei Formaten liefert Meta trotzdem aus, und zwar mit einem Quadrat in der
Story. Das ist der Zustand, den dieses Paket beendet.

## Das Seitenverhältnis gehört zum Modell

GPT Image 2 nimmt **4:5 in 2K nicht an** (*„for 2K resolution, the following
aspect ratios are not supported: 5:4, 4:5, 3:1, 1:3, and 9:21"*, docs.kie.ai).
Das Hochformat entsteht deshalb in 4K. Das kostet 8 statt 5 Cent, und die
Schrift bleibt scharf. 1K wäre billiger, aber an der Schrift ist das Modell
schon einmal gescheitert.

Welche Eingabefelder zu welchem Format gehören, steht in
`services.kie.formate` — in den Worten dieses Modells. **Fehlt ein Format
dort, wird es nicht erzeugt.** Ohne Seitenverhältnis wählt das Modell
`auto`, und die Grafik käme im falschen Format zurück, ohne dass jemand
einen Fehler sähe.

## Gleichzeitig, nicht nacheinander

Ein Auftrag braucht ein bis drei Minuten. Nacheinander wären es bis zu neun,
und der Auftrag in der Warteschlange hat zehn. Also werden alle drei
angelegt, bevor der erste abgefragt wird. Die Wartezeit bleibt die eines
einzelnen Bildes.

**Ein Fehlschlag nimmt den anderen nichts.** Jedes Format ist bezahlt,
sobald es beim Anbieter liegt. Scheitert eines, bleiben die beiden anderen
liegen, und der Entwurf sagt, welches fehlt.

## Stories haben Ränder, die nicht uns gehören

Oben liegen Profilbild und Name, unten Antwortfeld und Schaltflächen. Meta
empfiehlt, oben 14 %, unten 35 % und seitlich je 6 % frei zu lassen. Der
Auftrag für 9:16 nennt diese Zonen, damit die Schrift nicht unter der
Oberfläche der App verschwindet. Die Werte stehen in `mrs.ads.formate`.

## Bei Meta: eine Anzeige, Formate je Platzierung

Nicht drei Anzeigen. Drei Anzeigen wären drei Auslieferungen mit drei
Budgets im Wettbewerb gegeneinander, und die Kennzahlen zerfielen in drei
Teile. Meta sieht dafür *Placement Asset Customization* vor: ein Creative
mit `asset_feed_spec`, darin die drei Bilder mit je einem Label und
Regeln, welche Platzierung welches Label bekommt.

- **Eine Regel je Plattform**, wie in Metas Beispielen.
- **Die Auffangregel zuletzt**, mit leerer `customization_spec` — als
  JSON-**Objekt** `{}`, nicht als Liste `[]`. PHP macht aus einem leeren
  Array eine Liste.
- `optimization_type: PLACEMENT`, `ad_formats: ["SINGLE_IMAGE"]`.
- Text, Überschrift, Beschreibung, Ziel und Schaltfläche je einmal. Was leer
  ist, geht nicht mit (WP-27b, 23.09.2026).

**Regel 2 gilt auch für die Labels.** Sie heißen nach Merkmal und Format
(`anzeige_abcdefghij_4x5`) und tragen keine Katalogbezeichnung.

## Kontingent

**Ein Formatsatz zählt als eine Grafik** (B13, angepasst am 27.09.2026). Die
drei Formate sind keine Wahl der Praxis, sondern Pflicht jeder Anzeige.
Zählte jede Datei, würden aus 30 enthaltenen Grafiken stillschweigend 10
Anzeigen. Die Kosten bei kie.ai steigen von 5 auf rund 18 Cent je Satz,
gegen 2 Euro Verkaufspreis.

Gezählt wird weiter, was erzeugt wurde: ein Satz, von dem nur ein Format
ankam, hat Geld gekostet und zählt. Ein zweiter Satz zum selben Entwurf
zählt ein zweites Mal.

## Schritte

1. `Bildformat` (1x1, 4x5, 9x16), `mrs.ads.formate` mit Platzierungen und
   Schutzzone, `services.kie.formate` mit den Eingabefeldern je Format.
2. `ad_suggestion_images`: Entwurf, Anhang, Format, Satz. Bestehende
   Grafiken werden als 1:1 übernommen, jede als eigener Satz, damit die
   Abrechnung vergangener Monate stehen bleibt.
3. `Bildmodell::erzeuge()` nimmt je Format einen Auftrag und liefert einen
   `Bildsatz`: die Bilder, die ankamen, und die Gründe für die, die nicht
   ankamen.
4. `KieModell`: alle Aufträge anlegen, dann gemeinsam abfragen.
5. `Vorschlagslauf`: ein Auftrag je Format, Satzkennung, Teilfehler am
   Entwurf.
6. `Nutzungsuebersicht::bilder()` zählt Sätze.
7. `ads.image_hashes` statt `ads.image_hash`: je Format die Bildkennung von
   Meta und der Anhang, zu dem sie gehört.
8. `Anzeigenschaltung`: jedes Format hochladen, Creative mit
   `asset_feed_spec`.
9. Seite *Anzeigen*: das Detail zeigt jedes Format, *In Kampagne schalten*
   erst bei vollständigem Satz.

## Abnahmekriterien

**Erzeugen**

1. Ein Bildauftrag erzeugt je Format eine Grafik: 1:1, 4:5 und 9:16.
2. Jedes Format geht mit seinem eigenen Seitenverhältnis an kie.ai, 4:5 in
   4K.
3. Ein Format ohne eingestelltes Seitenverhältnis wird nicht beauftragt.
4. Alle Aufträge werden angelegt, bevor der erste abgefragt wird.
5. Der Auftrag für 9:16 nennt die Schutzzonen für Stories.
6. Jeder Formatauftrag enthält die Überschrift wörtlich und die Grenzen
   (keine Vorher-Nachher-Darstellung).
7. Scheitert ein Format, bleiben die anderen, und der Entwurf nennt das
   fehlende.
8. Scheitern alle, steht der Grund am Entwurf.

**Kontingent**

9. Ein Formatsatz zählt als eine Grafik.
10. Ein zweiter Satz zum selben Entwurf zählt ein zweites Mal, und der erste
    bleibt liegen.

**Schalten**

11. Ein Entwurf ohne vollständigen Satz lässt sich nicht schalten, und die
    Meldung nennt die fehlenden Formate.
12. Die Übertragung lädt jedes Format hoch.
13. Das Creative ordnet 4:5 dem Feed zu, 9:16 den Stories und 1:1 allem
    Übrigen — mit der Auffangregel als leerem Objekt, zuletzt.
14. Weder Labels noch Regeln tragen eine Katalogbezeichnung.
15. Ein zweiter Lauf lädt ein schon hochgeladenes Format nicht erneut hoch.
16. Eine Anzeige, deren Entwurf nicht mehr freigegeben ist, geht nicht
    hinaus — eine neue Grafik nach dem Schalten nimmt die Freigabe, und
    ungeprüft darf sie nicht zu Meta.

**Oberfläche**

17. Die Seite liefert je Entwurf alle Formate mit Adresse, und die Bildroute
    liefert jedes Format einzeln.
18. Eine zweite Grafik zeigt, dass sie entsteht — auch wenn schon eine da
    ist.

## Nicht in diesem Paket

- **Reels mit eigenem Format.** Metas Liste der Platzierungen für
  Asset-Customization-Regeln nennt `reels` nicht (Stand 28.06.2026). Reels
  bekommen deshalb die Auffangregel. Siehe „Offen".
- **1,91:1.** Meta führt es noch für Links und die rechte Spalte, empfiehlt
  dort aber 1:1. Ein viertes Format wäre ein viertes Bild mit einer vierten
  Schrift zum Lesen.
- **Ein einzelnes Format nachholen.** Scheitert eines, entsteht der nächste
  Satz vollständig neu.
- **Anzeigen, die schon bei Meta stehen.** Sie behalten ihr Quadrat, bis
  jemand sie neu schaltet.

## Fallstricke

- **Ein leeres PHP-Array wird `[]`.** Meta erwartet für die Auffangregel
  `{}`.
- **Ohne Seitenverhältnis kommt ein Bild zurück**, nur im falschen Format.
  Deshalb bricht das Produkt ab, statt `auto` zu schicken.
- **Die Schrift steht dreimal auf der Grafik.** Drei Chancen für
  „Garantieti". Das Detail zeigt jedes Format, damit jemand jedes liest.
- **Eine neue Grafik nach dem Schalten.** Die Übertragung liest die Bilder
  beim Übertragen, nicht beim Schalten. Ohne Prüfung der Freigabe ginge
  eine ungeprüfte Grafik hinaus.

## Stand

Die 18 Abnahmekriterien laufen: `tests/Feature/Anzeigen/FormateTest.php`
(**10 Tests**), vier neue in `KieModellTest.php` und sieben neue in
`tests/Feature/Werbung/AnzeigenschaltungTest.php`. Gesamtstand 1278 Tests,
4623 Zusicherungen. Pint, PHPStan Stufe 8, Prettier, ESLint und `vue-tsc`
sauber, 40 Seiten im Manifest.

Neu: `Bildformat`, `Bildsatz`, `Grafikablage`, `AdSuggestionImage`,
`ad_suggestion_images`, `ads.image_hashes`, `mrs.ads.formate`,
`services.kie.formate`, `KIE_RESOLUTION_4X5`. Die Tests der Bilderzeugung
teilen sich eine `Bildattrappe` statt zehn anonymer Klassen.

**Die Migration gegen echte Daten:** in der Entwicklungsumgebung wurden aus
14 bestehenden Grafiken 14 quadratische Sätze. Keine Datei blieb ohne
Datensatz, die Abrechnung vergangener Monate bleibt gleich. Rückrollen und
erneutes Einspielen laufen durch. Solche Entwürfe tragen „1 von 3 Formaten"
und brauchen eine neue Grafik, bevor sie sich schalten lassen.

**Im Browser durchgespielt**, mit Platzhaltergrafiken statt kie.ai: das
Detail zeigt die drei Formate als Miniaturen im richtigen Seitenverhältnis
und eines groß. Ein fehlendes Format steht als Warnung darin, *In Kampagne
schalten* ist dann gesperrt und sagt darunter, was fehlt. Die Kachel nennt
„1 von 3 Formaten" in der Fußzeile — nicht auf dem Bild, denn dessen untere
Hälfte gehört der Schrift der Grafik. In Telefonbreite geprüft.

## Was das Bauen zutage gefördert hat

**Die Übertragung prüfte die Freigabe nicht.** Sie liest die Bilder beim
Übertragen, nicht beim Schalten. Eine neue Grafik nach dem Schalten nimmt
dem Entwurf die Freigabe (WP-31), aber *Erneut übertragen* hätte sie
trotzdem hinausgetragen, ungeprüft. Mit drei Formaten wird genau dieser Weg
häufiger: ein Format fehlt, die Grafik wird neu erzeugt, die Anzeige erneut
übertragen. Jetzt bricht die Übertragung ab und sagt warum.

**„Entsteht gerade" galt nur beim ersten Mal.** Beim Nacherzeugen stand die
alte Grafik unverändert da, ohne Drehkreis, und ein Lauf, der verloren ging,
meldete nichts. Gefragt wird jetzt, ob *seit der Anforderung* etwas ankam.

## Offen

- **Gegen die echte Marketing-API ist die Formatzuordnung nicht geprüft.**
  Sie folgt Metas Beispielen wörtlich. Das ist nach der kie.ai-Erfahrung
  ausdrücklich kein Beweis. Auf Staging einmal mit
  `execution_options=["validate_only"]` gegen `/adcreatives` prüfen.
- **Reels.** Nimmt Meta `instagram_positions: ["reels"]` in einer Regel an,
  obwohl die Seite es nicht aufführt, gehören Reels zu 9:16. Mit
  `validate_only` prüfbar.
- **4K-Dateigröße.** Ein Hochformat in 4K liegt vermutlich zwischen 10 und
  20 MB. Meta nimmt bis 30 MB an, der Graph-Aufruf wartet 30 Sekunden.
  Beim ersten echten Lauf ansehen.
