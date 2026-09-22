# WP-27b · Anzeigen schalten

## Ziel
Der letzte Meter: aus einem freigegebenen Entwurf mit Grafik wird eine
Anzeige bei Meta. Und eine bestehende Kampagne lässt sich bearbeiten, nicht
nur starten und pausieren.

## Vorher lesen
- `specs/WP-27-kampagnenverwaltung.md` — der Weg, dem dieses Paket folgt
- `specs/WP-31-anzeigenvorschlaege.md` — woher Text und Grafik kommen
- `docs/entscheidungen.md` — **C9** Grenze zwischen Angebot und Person,
  **C10** erzeugte Bilder liegen bei uns
- `CLAUDE.md`, Regeln 2 und 4

## Voraussetzungen
WP-27, WP-30, WP-31. Für den echten Betrieb zusätzlich `ads_management`
(WP-00) und eine Facebook-Seite.

## Die Linie, an der alles hängt

**Nur freigegeben, nur mit Grafik.** Ein Entwurf ist ein Entwurf; eine
Anzeige ohne Bild liefert Meta nicht aus. Beides wird im Server geprüft,
nicht in der Oberfläche.

**Angelegt wird pausiert.** Eine Anzeige, die im Moment des Anlegens
ausliefert, lässt keinen Blick darauf zu, bevor sie Geld ausgibt — dieselbe
Regel wie bei der Kampagne in WP-27.

**Nie im Anfragezyklus** (Regel 4). Die Oberfläche merkt sich die Absicht,
ein Auftrag trägt sie hinaus.

## Regel 2 trennt Angebot und Person, nicht Wort und Wort

Der **Inhalt** der Anzeige darf die beworbene Leistung benennen (C9) — dafür
wirbt die Praxis. Der **Name** darf es nicht: er liegt unverschlüsselt, ist
in Metas Oberfläche überall sichtbar und friert am Termin als
`attribution_snapshot` ein. Deshalb heißt eine Anzeige `Anzeige [merkmal]`
und sonst nichts.

Ein Test hält es fest: keiner der aktiven Katalognamen steht im Namen.

## Schritte

1. `ads` bekommt, was `ad_campaigns` seit WP-27 hat: `sync_state`,
   `sync_error`, `client_token`, `managed_by_us` — dazu `ad_suggestion_id`
   (woraus sie entstand) und `image_hash` (damit ein zweiter Lauf das Bild
   nicht erneut hochlädt).
2. `ad_accounts.page_external_id` — die Facebook-Seite als Absender.
3. `App\Werbung\Verwaltung\Anzeigenschaltung`: `plane`, `uebertrage`,
   `passeAn`, `uebertrageAenderung`, `vermerkeFehler`.
4. `AnzeigeUebertragen` auf der Warteschlange `default`.
5. `Kampagnenverwaltung::passeZielgruppeAn` und die Übertragung der
   geänderten Zielgruppe.
6. *Anzeigen*: **In Kampagne schalten** bei freigegebenen Entwürfen mit
   Grafik.
7. *Kampagnen*: **Bearbeiten** (Tagesbudget, Umkreis, Alter, Geschlecht) und
   ein Feld für die Facebook-Seite.

## Die Übertragung, drei Schritte

| | Pfad | Ergebnis |
|---|---|---|
| Bild | `{konto}/adimages` mit `bytes` (Base64) | `images.*.hash` |
| Creative | `{konto}/adcreatives` mit `object_story_spec` | `id` |
| Anzeige | `{konto}/ads` mit `adset_id` und `creative` | `id` |

**Erst nachsehen, dann anlegen.** Metas Marketing-API kennt keinen
Idempotenzschlüssel; ein Auftrag, dessen Antwort verlorenging, legte beim
zweiten Versuch eine zweite Anzeige an — und die kostet ein zweites Mal
Geld. Das Merkmal im Namen macht sie wiederauffindbar, genau wie bei der
Kampagne.

**Das Ziel ist die eigene Buchungsseite.** Eine fremde Zielseite könnte
alles behaupten; geprüft haben wir nur unsere.

**Die Schaltfläche ist „Mehr erfahren"**, nicht „Jetzt buchen". Ein
Versprechen im Werbemittel ist genau das, was das HWG nicht mag — und ein
Termin ist erst einer, wenn die Praxis ihn bestätigt hat.

## Abnahmekriterien

1. Ein Entwurf, der nicht freigegeben ist, lässt sich nicht schalten.
2. Ein Entwurf ohne Grafik lässt sich nicht schalten.
3. Geschaltet wird lokal und pausiert, mit Auftrag — Meta wird im
   Anfragezyklus nicht gerufen.
4. Derselbe Entwurf geht nicht zweimal in dieselbe Kampagne.
5. Die Übertragung lädt das Bild hoch, legt das Creative an und dann die
   Anzeige — pausiert.
6. Der Name der Anzeige trägt keine Katalogbezeichnung.
7. Ein zweiter Auftrag legt keine zweite Anzeige an.
8. Eine fehlende Berechtigung lässt die Anzeige **offen** (die Absicht
   bleibt) — der Hinweis hängt am Werbekonto.
9. Eine fachliche Ablehnung steht im Klartext an der Anzeige.
10. Das Tagesbudget lässt sich ändern und wird übertragen.
11. Die Zielgruppe lässt sich ändern und wird übertragen.
12. Unter Metas Mindestbudget geht es nicht.
13. Zwei Mandanten sehen ausschließlich ihre eigenen Anzeigen.

## Nicht in diesem Paket

- **Den Namen ändern.** Er gehört dem Produkt (C9).
- **Mehrere Anzeigengruppen je Kampagne.** Eine Kampagne, eine Zielgruppe.
- **Videos.**
- **Anzeigen aus Metas Bestand bearbeiten.** Wir lesen sie, wir ändern sie
  nicht.

## Fallstricke

- **Eine zweite Anzeige kostet ein zweites Mal.** Ohne das Merkmal im Namen
  legt jeder wiederholte Auftrag eine an.
- **Ohne Facebook-Seite kein Creative.** Sie ist der Absender, und Meta
  lehnt ohne sie ab. Deshalb ein Feld dafür und ein deutscher Satz, wenn es
  leer ist.
- **Die Zielgruppe hängt an der Anzeigengruppe**, das Budget an der
  Kampagne. Die Oberfläche bedient beides aus einem Formular, weil eine
  Praxis diese Ebene nicht unterscheiden will — der Server tut es.

## Stand

Die 13 Abnahmekriterien laufen:
`tests/Feature/Werbung/AnzeigenschaltungTest.php` (**13 Tests**).
Gesamtstand 1048 Tests, 3702 Zusicherungen. PHPStan Stufe 8 sauber,
`vue-tsc` sauber, 39 Seiten.

Neu: `Anzeigenschaltung`, `AnzeigeUebertragen`, `Graphschreiber::legeRoh`,
`Kampagnenverwaltung::passeZielgruppeAn`, `ad_accounts.page_external_id`,
fünf Spalten an `ads` — dazu *In Kampagne schalten* auf der Anzeigenseite
und *Bearbeiten* auf der Kampagnenseite.

## Offen

- **`ads_management`** (WP-00). Bis dahin bleibt jede geschaltete Anzeige
  *wird übertragen*, und der Hinweis steht am Werbekonto. Das ist kein
  Fehler des Produkts, sondern der Zustand der Berechtigung — sichtbar
  gemacht statt verschwiegen.
- **Gegen die echte Marketing-API ist nichts geprüft.** Die drei Aufrufe
  folgen der Dokumentation. Nach der kie.ai-Erfahrung ist das ausdrücklich
  kein Beweis: dort lag eine geratene Anbindung an vier Stellen daneben.
- **Die Facebook-Seite wird abgetippt.** Sie zu lesen bräuchte
  `pages_show_list` — eine Berechtigung mehr im App Review, für eine
  Bequemlichkeit.
