# WP-16 · Kontakte & Kanalidentitäten

## Ziel
Dieselbe Person bleibt dieselbe Person — über Kanäle hinweg, über Schreibweisen
hinweg, und ohne dass zwei Personen versehentlich eine werden.

## Vorher lesen
- `docs/entscheidungen.md` — **D1** `contacts` statt `patients`; **D5**
  Kanalidentitäten als Brücke; **D6** automatischer Merge nur bei identischer
  E-Mail oder Telefonnummer; **D7** `contact_merges` mit Snapshot, umkehrbar;
  **D10** keine organisationsübergreifenden Kontakte; **A12** kein Soft
  Delete; **P8** Suche nur exakt
- `docs/datenmodell.md`, Abschnitt 4
- `CLAUDE.md`, Regeln 1 und 3

## Voraussetzungen
WP-11

## Der Anfang von M2 — und eine offene Rechnung aus WP-11

WP-11 hat `contacts` im Mindestumfang angelegt: vier verschlüsselte Felder,
blinde Indizes auf E-Mail und Nachname. Die Telefonnummer fehlt dort
**ausdrücklich**, mit Begründung im Code:

> Ohne E.164-Normalisierung ergeben „+49 170 1234567" und „01701234567" zwei
> verschiedene Hashes, und die Suche träfe still nie.

Das ist der teuerste Fehlertyp dieses Produkts: kein Fehler, keine Meldung,
nur ein Ergebnis, das nicht kommt. Diese Rechnung wird hier beglichen.

## Normalisieren ist kein Aufhübschen

Ein blinder Index vergleicht Hashes. Zwei Schreibweisen derselben Nummer
ergeben zwei Hashes, und damit zwei Personen, die niemand zusammenführt.
Normalisierung ist deshalb keine Kosmetik an der Eingabe, sondern die
Voraussetzung dafür, dass Gleichheit überhaupt feststellbar ist.

**Eine eigene Umsetzung wäre hier der falsche Sparzwang.** Telefonnummern
haben Durchwahlen, Servicenummern, Auslandspräfixe in zwei Schreibweisen und
Sonderfälle, die man beim Selberbauen genau so lange nicht bemerkt, bis eine
Praxis anruft. `giggsey/libphonenumber-for-php-lite` ist die Umsetzung von
Googles Referenzbibliothek; sie kommt ohne Geodaten aus und ist damit klein
genug.

**Was sich nicht normalisieren lässt, wird gespeichert, aber nicht
indiziert.** Eine unleserliche Nummer ist ein Kontaktweg, den jemand
abtippen kann — sie zu verwerfen wäre schlimmer als sie nicht zu finden. Der
blinde Index bleibt dann leer, und die Suche sagt nichts falsch.

## Kanalidentitäten: die Brücke, nicht die Person

Entscheidung D5. Eine Person schreibt über Instagram, ruft später an und
bucht am Ende über die Website. Das sind drei Kennungen und **ein** Mensch.

Zwei Eigenheiten, die das Modell prägen:

**Eine Identität darf ohne Kontakt existieren.** Die erste Nachricht kommt an,
bevor irgendjemand weiß, wer da schreibt. Ein Modell, das eine Zuordnung
erzwingt, erzeugt an dieser Stelle Karteileichen — oder falsche Kontakte.

**Meta vergibt Kennungen je Seite unterschiedlich** (`docs/datenmodell.md`,
Abschnitt 4). Dieselbe Kennung auf zwei Kanälen ist nicht dieselbe Person,
und zwei Organisationen, die dieselbe Kennung sehen, sehen zwei verschiedene
Identitäten (D10). Eindeutig ist deshalb das Tripel aus Organisation, Kanal
und Kennung.

Die Kennung selbst ist personenbezogen — sie identifiziert einen Menschen bei
einem Anbieter. Sie liegt verschlüsselt, mit blindem Index für den
Gleichheitsvergleich (Regel 3).

## Zusammenführen: automatisch nur bei hartem Signal

Entscheidung D6 zieht die Linie:

| Signal | Folge |
|---|---|
| identische E-Mail | führt automatisch zusammen |
| identische Telefonnummer (normalisiert) | führt automatisch zusammen |
| gleicher Name, gleicher Geburtstag, Ähnlichkeit | **Vorschlag**, sonst nichts |

Der Grund steht in beiden Richtungen: zwei Datensätze derselben Person sind
ärgerlich und reparierbar. Zwei Personen in einem Datensatz sind ein
Datenschutzvorfall — und in einer ästhetischen Praxis bedeutet es, dass eine
Person die Termine einer anderen sieht.

**Ein Vorschlag ändert nichts.** Er steht in der Oberfläche, bis ein Mensch
ihn annimmt oder verwirft.

## Umkehrbar heißt: mit Snapshot

Entscheidung D7. Zusammenführen bewegt Termine und Identitäten auf den
Gewinner und löscht den Verlierer — echt, ohne Soft Delete (A12). Was
verloren ginge, steht vorher im Snapshot: die Felder des Verlierers und die
Liste dessen, was verschoben wurde.

Der Snapshot enthält selbst Personendaten und liegt deshalb verschlüsselt und
mit Ablaufdatum. Nach 30 Tagen räumt ihn der Retention-Job weg (WP-18) — ab
dann bleibt der Vorgang im Protokoll, aber nicht mehr umkehrbar. Das gehört
in die Oberfläche, nicht in eine Fußnote.

## Schritte

1. `Telefonnummer` — E.164 mit Standardregion aus `config/mrs.php`, plus
   Anzeigeformat.
2. `contacts` um `phone_bidx` ergänzen; `BlindIndex::normalize()` bekommt die
   Telefonnummer als eigenen Weg.
3. `ChannelType` als Enum (Entscheidung A11), `channel_identities`.
4. `contact_merges` mit verschlüsseltem Snapshot und `expires_at`.
5. `App\Kontakte\Kontaktsuche` — aus `App\Termine` hierher, um die
   Telefonnummer erweitert.
6. `Zusammenfuehrung` — automatisch, vorgeschlagen, umkehrbar.
7. Seite „Kontakte": Liste, Kanäle, Vorschläge, Zusammenführen mit
   Gegenüberstellung.

## Abnahmekriterien

**Telefonnummern**

1. `+49 170 1234567`, `0170 1234567` und `0049-170-1234567` ergeben denselben
   blinden Index.
2. Eine nicht normalisierbare Nummer wird gespeichert, aber nicht indiziert.
3. Die Suche findet den Kontakt unabhängig von der Schreibweise der Eingabe.
4. Die Standardregion steht in `config/mrs.php`, nicht im Code.

**Kanalidentitäten (D5, D10)**

5. Eine Person mit zwei Kanälen hat zwei Identitäten und einen Kontakt.
6. Eine Identität entsteht auch ohne Kontakt.
7. Dieselbe Kennung auf demselben Kanal entsteht nur einmal — auf
   **Datenbankebene**.
8. Dieselbe Kennung in zwei Organisationen sind zwei Identitäten.
9. Die Kennung liegt verschlüsselt und ist trotzdem exakt auffindbar.

**Zusammenführen (D6)**

10. Identische E-Mail führt automatisch zusammen.
11. Identische Telefonnummer führt automatisch zusammen, auch bei
    unterschiedlicher Schreibweise.
12. Gleicher Name allein führt **nicht** zusammen, sondern schlägt vor.
13. Ein Vorschlag verändert keine Daten.

**Umkehrbarkeit (D7, A12)**

14. Nach dem Zusammenführen hängen Termine und Identitäten am Gewinner.
15. Der Verlierer ist gelöscht, nicht markiert.
16. Der Snapshot liegt verschlüsselt — im Klartext steht er nirgends.
17. Rückgängig stellt den Kontakt und seine Zuordnungen wieder her.
18. Nach Ablauf des Snapshots ist Rückgängig nicht mehr möglich, und der
    Vorgang bleibt trotzdem sichtbar.
19. Ein Merge über Organisationsgrenzen ist ausgeschlossen.

**Suche (P8)**

20. Nachname exakt findet, Teilstring findet nicht.
21. Die Oberfläche sagt, dass nur exakt gesucht wird.

**Zugang und Mandant**

22. Ohne `contacts.manage` kein Zugriff.
23. Die Liste zeigt nie einen Kontakt einer anderen Organisation.

## Nicht in diesem Paket

**Leads und Pipeline** (WP-17). `contacts` und `leads` sind getrennt
(Entscheidung D3), und die Regel, wann ein neuer Lead entsteht (D4), gehört
dorthin.

**Einwilligungen** (WP-18) — auch wenn sie laut D8 an der Kanalidentität
hängen. Hier entsteht die Identität, dort das Einverständnis. Ebenso Notizen,
Anhänge, Aufbewahrung und der Auskunftsexport.

**Konversationen und Nachrichten** (WP-19, WP-20). Eine Kanalidentität ohne
Kanalanbindung ist hier ausdrücklich in Ordnung: sie entsteht aus einer
Buchung oder von Hand, bis die Kanäle sie füllen.

**Automatisches Zusammenführen von Kanalidentitäten** über Anbietersignale.
`docs/datenmodell.md` ist da deutlich: „Zusammenführung von
`channel_identities` nur bei sicherem Signal." Welche Signale Meta liefert,
entscheidet sich mit WP-20.

**Dublettensuche über Ähnlichkeit.** Ohne entschlüsselte Felder gibt es kein
Levenshtein — Vorschläge entstehen aus exakten Übereinstimmungen auf
Feldern, die für sich allein nicht genügen (Nachname plus Vorname).

## Fallstricke

- **Ohne Normalisierung trifft die Suche still nie.** Kein Fehler, keine
  Meldung, kein Ergebnis.
- **Zwei Personen in einem Datensatz sind nicht reparierbar** wie zwei
  Datensätze einer Person. Im Zweifel: Vorschlag, nicht Merge.
- **Eine Kanalidentität ist nicht die Person.** Wer beides gleichsetzt, führt
  beim ersten geteilten Familienanschluss zwei Menschen zusammen.
- **Der Snapshot ist selbst personenbezogen.** Verschlüsselt, befristet, und
  niemals im Protokoll.
- **Kein Soft Delete** (A12). Eine Löschanfrage muss echt löschen — ein
  `deleted_at` auf `contacts` wäre genau die Hintertür, die das verhindert.

## Stand

Alle 23 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Kontakte/` — **42 Tests**. Gesamtstand 470.

`giggsey/libphonenumber-for-php-lite` ist die einzige neue Abhängigkeit des
Pakets. Die Seite „Kontakte" steht in der Hauptnavigation unter *Betrieb*,
zwischen Terminen und den Praxisstammdaten.

Auf den Demodaten nachgewiesen: die Suche nach `0170 1112223` findet den
Kontakt, der als `+49 170 1112223` angelegt wurde.

## Was das Bauen zutage gefördert hat

**Ein neuer blinder Index macht bestehende Daten unauffindbar.** Die
Migration legt die Spalte an; gefüllt wird sie vom `saving`-Haken, und der
läuft für eine Zeile, die niemand mehr anfasst, nie. Auf den Demodaten war
danach kein einziger Kontakt über die Telefonnummer zu finden — kein Fehler,
keine Meldung, nur ein Ergebnis, das nicht kommt. **Genau die Fehlerklasse,
gegen die das Paket antritt**, diesmal verursacht von seiner eigenen
Migration.

`mrs:blindindex-nachtragen` trägt nach, mit Trockenlauf. Es findet seine
Modelle über die Schnittstelle statt über eine Liste — WP-18 und WP-20
bringen weitere, und eine Aufzählung wäre die Stelle, die dann vergessen
wird.

**Der erste Wurf war nicht idempotent.** Er stieß den `saving`-Haken an,
indem er das Quellfeld neu setzte — und schrieb damit jedes verschlüsselte
Feld mit neuem Nonce zurück. Jede Zeile galt bei jedem Lauf als geändert.
`blindIndexHash()` rechnet den Index jetzt, ohne etwas anzufassen; gespeichert
wird nur, was sich wirklich unterscheidet, und zwar still (kein
Protokolleintrag je Zeile).

**Die Suche gehört auf den Server, nicht in die Tabelle.** `DataTable` filtert
die geladenen Zeilen per Teilstring — auf einer Kontaktliste hätte das eine
Suche vorgetäuscht, die es nicht gibt (P8). Das Suchfeld der Seite läuft
deshalb über den Server, und die Tabelle bekommt `suchfelder="[]"`.

**Der Testaufbau hatte eine stille Annahme.** Zwei Merge-Tests prüften, dass
leere Felder des Gewinners ergänzt werden — die `ContactFactory` setzt aber
bereits eine Telefonnummer. Die Tests waren falsch, nicht der Code.

## Offen

> **Nachtrag 26.09.2026.** Alle drei Punkte sind erledigt: Kanalidentitäten
> entstehen aus Nachrichten (WP-19/WP-20), der Aufräumjob für Snapshots läuft
> über `mrs:aufbewahrung` (WP-18), der Auskunftsexport steht
> (`contacts.export`).

**Kanalidentitäten entstehen hier nur von Hand.** Aus Nachrichten füllen sie
sich mit WP-19 und WP-20; welche Signale von Meta ein Zusammenführen
rechtfertigen, entscheidet sich dort.

**Der Aufräumjob für abgelaufene Snapshots** gehört zu WP-18. Bis dahin
bleiben sie liegen — sichtbar als „nicht mehr umkehrbar", aber nicht
gelöscht. Die Frist steht in `config/mrs.php`.

**Kontakte lassen sich löschen, aber es gibt keinen Auskunftsexport** (WP-18).
