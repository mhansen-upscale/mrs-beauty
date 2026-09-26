# WP-17 · Leads & Pipeline

## Ziel
Jede Anfrage ist als Vorgang sichtbar — von „meldet sich" bis „behandelt" —
und zählbar, ohne dass jemand die Definition auslegen muss.

## Vorher lesen
- `docs/entscheidungen.md` — **D2** Behandlungswunsch als `treatment_id`, nie
  als Freitext; **D3** `leads` und `contacts` getrennt; **D4** wann ein neuer
  Lead entsteht; **C7** Aufbewahrung
- `docs/fachlogik/attribution.md`, Abschnitt **Kennzahlen** — dort stehen die
  Definitionen, die dieses Paket bedienen muss
- `CLAUDE.md`, Regeln 1 und 3

## Voraussetzungen
WP-16

## Warum ein Lead nicht der Kontakt ist

Entscheidung D3 trennt beide, und der Grund steht in einem Halbsatz:
*„Mehrfachanfragen sind der Normalfall; Attribution braucht die Anfrage als
Einheit."*

Dieselbe Person fragt im März nach Botox, bucht nicht, meldet sich im Oktober
wegen Hyaluron und bucht dann. Das sind **zwei** Anfragen mit zwei Quellen,
zwei Kampagnen und zwei Ergebnissen. Wer sie am Kontakt festmacht, kann
hinterher nicht mehr sagen, welche Anzeige die Buchung gebracht hat — und
genau das ist die Zahl, die das Abo rechtfertigt.

## Wann ein neuer Lead entsteht

Entscheidung D4, und sie ist in beide Richtungen scharf:

| | Folge |
|---|---|
| Es gibt einen offenen Lead zur selben Behandlung, jünger als die Frist | **kein** neuer Lead |
| Die Behandlung weicht ab | neuer Lead |
| Der letzte Lead ist länger als die Frist ohne Aktivität | neuer Lead |
| Alle Leads sind geschlossen | neuer Lead |

**Beides wäre schlimm.** Für jede Nachricht einen Lead anzulegen ergibt
Lead-Inflation: die Kosten pro Lead sehen künstlich gut aus, und die Pipeline
ist unbrauchbar. Alles an einen Lead zu hängen ergibt Lead-Verklumpung: eine
Person mit fünf Anfragen über zwei Jahre ist ein einziger Vorgang, und die
Auswertung verliert vier davon.

Die Frist steht als `leads.reopen_after_inactive_days` in `config/mrs.php` —
seit WP-02, mit 90 Tagen.

## Der Wunsch ist eine Kennung, kein Satz

Entscheidung D2: **`treatment_id`, nie Freitext.** Der Grund ist kein
Datenmodellgeschmack, sondern Artikel 9 DSGVO — ein Freitextfeld füllt sich
binnen Wochen mit „Lippen nachkorrigieren, letztes Mal asymmetrisch", und das
landet dann in Logs, Kalendertiteln und Meta-Payloads (Regel 2).

Ausführbar abgesichert: die Tabelle hat **keine** Freitextspalte für den
Wunsch, und ein Test sucht danach.

**Ein Lead ohne Wunsch ist der Normalfall**, nicht die Ausnahme. Die erste
Nachricht lautet oft nur „Was kostet das?".

## Speed-to-Lead ist eine Kennzahl, keine Anzeige

`docs/fachlogik/attribution.md` führt sie als Median über
`leads.first_response_seconds`. Gemessen wird ab Entstehung des Leads bis zur
**ersten** Reaktion der Praxis — ein Statuswechsel weg von „neu" oder ein
angelegter Termin.

Nur die erste. Eine zweite Antwort verbessert die Zahl nicht, und eine
Kennzahl, die sich durch Nacharbeit schönen lässt, ist keine.

## Was die Pipeline aus Terminen lernt

Der Lead folgt dem Termin, nicht umgekehrt:

| Am Termin passiert | Der Lead |
|---|---|
| gebucht | steht auf **Termin** |
| erschienen | ist **gewonnen** |
| abgesagt | ist wieder **offen** |

„Abschlüsse" heißt laut Kennzahlentabelle „Leads mit Status `won`" — und ein
Abschluss ist erst ein Abschluss, wenn jemand dagewesen ist. Ein gebuchter
Termin, der nicht wahrgenommen wird, darf nicht als Erfolg zählen; sonst misst
der ROAS Absichten statt Umsatz.

## Schritte

1. `LeadStatus`, `LeadSource`, `LeadLostReason` als Enums (Entscheidung A11).
2. `leads` — Kontakt, Behandlung, Status, Quelle, erste Reaktion, letzte
   Aktivität. Ohne Freitext.
3. `Leadverwaltung` — D4 an einer Stelle, nicht an vier Aufrufstellen.
4. Anbindung an `Terminplaner`: buchen, einlösen, erschienen, absagen.
5. Anbindung an die öffentliche Buchungsseite (WP-12).
6. Seite „Anfragen" mit Trichter und Pipeline.

## Abnahmekriterien

**Entstehung (D3, D4)**

1. Eine Anfrage erzeugt einen Lead; der Kontakt bleibt ein eigener Datensatz.
2. Dieselbe Person mit derselben Behandlung erzeugt keinen zweiten offenen
   Lead.
3. Eine abweichende Behandlung erzeugt einen zweiten.
4. Nach Ablauf der Frist ohne Aktivität erzeugt dieselbe Anfrage einen neuen.
5. Ein gewonnener oder verlorener Lead blockiert keinen neuen.
6. Die Frist kommt aus `config/mrs.php`.

**Behandlungswunsch (D2)**

7. Der Wunsch ist eine `treatment_id`; die Tabelle hat **keine**
   Freitextspalte — ausführbar geprüft.
8. Ein Lead ohne Behandlungswunsch ist zulässig und zählt mit.

**Pipeline**

9. Ein gebuchter Termin setzt den Lead auf „Termin".
10. Ein erschienener Termin gewinnt den Lead.
11. Eine Absage öffnet den Lead wieder.
12. „Verloren" verlangt einen Grund.
13. Ein geschlossener Lead trägt den Zeitpunkt des Abschlusses.

**Speed-to-Lead**

14. Die erste Reaktion wird in Sekunden festgehalten.
15. Eine zweite Reaktion ändert die Zahl nicht.
16. Ohne Reaktion bleibt sie leer.

**Kennzahlen**

17. „Abschlüsse" zählt genau die Leads mit Status `won`.
18. Der Trichter zählt jede Anfrage einmal.

**Zugang und Mandant**

19. Ohne `contacts.manage` kein Zugriff.
20. Nie ein Lead einer anderen Organisation.

## Nicht in diesem Paket

**Attribution.** Besucher, Touches, Kampagnenzuordnung und
`attribution_snapshot` gehören zu WP-32. Hier entsteht nur die Einheit, an der
das später hängt — die grobe Quelle (`LeadSource`) sagt, *wie* jemand
hereinkam, nicht *woher*.

**Aufbewahrung.** „Lead ohne Termin nach 12 Monaten löschen" (C7) ist WP-18.
Diese Reihenfolge ist Absicht: eine Löschregel für eine Tabelle zu bauen, die
es nicht gibt, geht schief.

**Automatische Statuswechsel aus Nachrichten** (WP-20, WP-22). Der Agent wird
später melden, dass jemand geantwortet hat; heute tut es das Team.

**Ein Kanban-Brett.** Die Pipeline ist eine Liste mit Trichter. Ein Brett mit
Ziehen und Ablegen ist eine eigene Aufgabe und keine Voraussetzung dafür,
dass die Zahlen stimmen.

## Fallstricke

- **Lead-Inflation und Lead-Verklumpung** sind zwei Fehler mit derselben
  Ursache: D4 nicht an einer Stelle umgesetzt.
- **Freitext beim Behandlungswunsch** ist der kürzeste Weg, Art.-9-Daten in
  einen Meta-Payload zu bekommen.
- **Gewonnen heißt erschienen**, nicht gebucht. Sonst misst der ROAS
  Absichten.
- **Speed-to-Lead misst die erste Reaktion.** Wer sie überschreibt, macht die
  Kennzahl wertlos.

## Stand

Alle 20 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Leads/` — **28 Tests**. Gesamtstand 498.

Die Seite „Anfragen" steht in der Hauptnavigation unter *Betrieb*, über den
Kontakten. Oben der Trichter mit einer Zahl je Stufe und dem Median der
ersten Reaktion; die Stufen sind zugleich der Filter.

Auf den Demodaten nachgewiesen: dieselbe Person mit zwei Anfragen — Botox und
Hyaluron — als zwei Vorgänge. Genau der Fall, für den Entscheidung D3 die
Trennung von Kontakt und Lead verlangt.

## Was das Bauen zutage gefördert hat

**„Vom Empfang" ist keine Quelle.** Der erste Entwurf bildete
`BookingChannel::Internal` auf `LeadSource::Phone` ab — das klingt plausibel
und ist erfunden. Ein Teil dieser Buchungen kommt aus einem Anruf, ein Teil
von der Tür, ein Teil aus einer Anzeige. `docs/fachlogik/attribution.md` sagt
selbst, dass WP-32 die Quelle beim Anlegen zum Pflichtfeld macht; bis dahin
ist sie **unbestimmt**, und eine erfundene Quelle ist schlechter als keine.

**Der Lead hängt nicht am Termin, sondern wird über Kontakt und Behandlung
gefunden.** Ein Fremdschlüssel wäre falsch: die Stammkundin ruft an und
bekommt einen Termin, ohne dass je jemand eine Anfrage erfasst hätte. Umgekehrt
soll eine Anfrage, aus der ein Termin wird, nicht plötzlich zwei Vorgänge
sein.

**Die Demodaten hatten keine Leads**, weil ihre Termine aus der Zeit davor
stammen — dasselbe Muster wie der blinde Index in WP-16, nur diesmal ohne
Fehlerwirkung: Leads entstehen ab jetzt, und Geschichte nachzuerfinden wäre
schlechter, als sie fehlen zu lassen. Für die Vorführung sind sie über
denselben Weg nachgezogen worden, den eine Buchung heute nimmt.

**Der Trichter zählt unabhängig vom Filter.** Wer eine Stufe anklickt, filtert
die Liste — die Zahlen darüber bleiben, sonst wäre es kein Trichter, sondern
eine Tautologie.

## Offen

> **Nachtrag 26.09.2026.** Attribution (WP-32) und Aufbewahrung (WP-18)
> stehen. **Der Statuswechsel aus Nachrichten** auch: eine Antwort im
> Posteingang — von einem Menschen oder dem Assistenten — vermerkt die
> Reaktion selbst (`Leadverwaltung::beiAntwort`, `tests/Feature/Leads/ReaktionTest.php`).
> Speed-to-Lead misst damit, was geschah.

**Attribution.** Besucher, Touches, Kampagnenzuordnung und
`attribution_snapshot` sind WP-32. Die grobe Herkunft sagt *wie*, nicht
*woher*.

**Aufbewahrung.** „Lead ohne Termin nach 12 Monaten löschen" (C7) ist WP-18 —
jetzt gibt es die Tabelle, auf die sich die Regel bezieht.

**Statuswechsel aus Nachrichten** (WP-20, WP-22). Heute vermerkt das Team die
Reaktion; der Agent kann das später selbst.

**Zugeordneter Umsatz** braucht `treatments.avg_revenue_cents` (Entscheidung
D14, beim Onboarding zu erheben) und die Auswertung in WP-32.
