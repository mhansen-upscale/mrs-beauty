# WP-27 · Kampagnenverwaltung

## Ziel
Die Praxis steuert ihre Kampagnen aus dem Produkt — und die Oberfläche
erzwingt dabei, was Meta und das deutsche Recht verlangen, statt auf die
Ablehnung zu warten.

## Vorher lesen
- `docs/integrationen/meta.md` — vollständig, besonders *Werbung für
  ästhetische Eingriffe*, *Rate Limits und Fehler*, *Datenschutz*
- `specs/WP-26-werbekonto-anbindung.md` — die Struktur, die hier geändert
  wird, und **die Regel, die dieses Paket bricht**
- `docs/entscheidungen.md` — **B1** Werbekonto gehört dem Kunden, **B2** kein
  Live-Aufruf im Request, **C8** keine Custom Audiences, **C9** Grenze
  zwischen Angebot und Person
- `CLAUDE.md`, Regeln 1, 2 und 4

## Voraussetzungen
WP-26. **WP-00 für den Betrieb**: `ads_management` ist die Berechtigung, die
im App Review abgelehnt wird. Gebaut und geprüft wird dieses Paket ohne sie,
gegen das eigene Werbekonto.

## Die Linie, an der alles hängt

**Die Oberfläche erzwingt die Anforderungen, statt die API-Ablehnung
anzuzeigen** (`docs/produkt.md`, Das Differenzierungsmerkmal).

Meta behandelt kosmetische Verfahren als eingeschränkte Kategorie. Eine
Kampagne, die beim Speichern eine englische Meta-Fehlermeldung zurückgibt,
lässt das Produkt kaputt aussehen — und die Praxis weiß danach immer noch
nicht, was sie ändern soll.

Drei Dinge stehen deshalb **vor** der Übermittlung fest:

1. **Mindestalter.** Keine Bewerbung ästhetischer Eingriffe an Minderjährige.
   Das Feld lässt gar nichts anderes zu.
2. **Kein Interessen-Targeting.** „Botox" als Interesse auszuwählen wäre eine
   Behandlungsbezeichnung Richtung Meta — Regel 2, in einem Feld, an das
   niemand denkt. Zielgruppe ist Umkreis, Alter, Geschlecht. Mehr nicht.
   Keine Custom Audiences (C8).
3. **Mindestbudget.** Unter Metas Untergrenze liefert eine Anzeige nicht aus,
   und das Geld liegt trotzdem fest.

## Was dieses Paket schreibt — und was nicht

**Struktur, keine Inhalte.** Kampagne und Anzeigengruppe: Ziel, Budget,
Laufzeit, Umkreis, Alter, Geschlecht, Zustand. Anzeigentexte und Bilder
gehören zu WP-31 — und **davor muss die HWG-Prüfung aus WP-30 stehen**
(`docs/integrationen/meta.md`: „läuft **vor** jeder Übermittlung an Meta").

Das ist keine Sparsamkeit, sondern die Reihenfolge: Werbeaussagen ohne
Prüfwerk zu veröffentlichen wäre genau der Fehler, den das
Differenzierungsmerkmal verhindern soll. Struktur trägt keine Werbeaussage —
mit einer Ausnahme, und die ist der nächste Abschnitt.

## Der Name ist die Ausnahme — und die Lösung

Ein Kampagnenname ist das einzige Stück Text, das dieses Paket an Meta
schickt. Er bleibt **neutral** (C9, und der Grund steht in `CLAUDE.md`: er
liegt unverschlüsselt und friert ab WP-32 als `attribution_snapshot` am
Termin ein).

Deshalb erzeugt **das Produkt** den Namen aus Zeitraum, Ziel und Standort —
nicht die freie Eingabe der Praxis. Aus der Not wird der Mechanismus:

**Der Name trägt ein kurzes, bedeutungsloses Merkmal, und das macht das
Anlegen wiederholbar.** Metas Marketing-API kennt keinen
Idempotenzschlüssel. Ein Auftrag, dessen Antwort verlorenging, legt beim
zweiten Versuch eine zweite Kampagne an — mit zweitem Budget. Mit dem Merkmal
im Namen sieht der zweite Versuch zuerst nach, ob es die Kampagne schon gibt,
und übernimmt sie.

## Schreibende Aufrufe sind anders

- **Nie im Request-Zyklus** (B2, Regel 4). Die Oberfläche merkt sich die
  Absicht, ein Auftrag trägt sie hinaus.
- **Nicht blind wiederholen.** Beim Lesen ist eine Wiederholung harmlos; beim
  Anlegen kann der erste Versuch geglückt sein und nur die Antwort verloren.
  Wiederholt wird erst nach dem Nachsehen.
- **Der Zustand gehört ins Produkt.** Eine Änderung, die noch unterwegs ist,
  sieht anders aus als eine, die angekommen ist, und anders als eine, die
  Meta abgelehnt hat. Eine Ablehnung steht **im Klartext** da, nicht als Code.

## Die Regel aus WP-26 wird enger, nicht aufgehoben

WP-26 sichert zu: unter `app/Werbung` existiert kein schreibender
Graph-Aufruf. Dieses Paket bricht das — und zwar an **genau einer Stelle**,
die im Test namentlich steht. Alles andere bleibt schreibfrei.

Eine Ausnahmeliste mit einem Eintrag ist eine Regel. Eine ohne Liste ist
keine.

## Schritte

1. `AdCampaign` und `AdSet` um Absicht und Zustand erweitern: `sync_state`,
   `sync_error`, `client_token`, `managed_by_us`.
2. `App\Werbung\Verwaltung\Graphschreiber` — die **eine** schreibende Stelle.
3. `App\Werbung\Verwaltung\Kampagnenplan` — die Absicht als Wertobjekt, mit
   allen Prüfungen: Mindestalter, Mindestbudget, erlaubte Ziele, kein
   Interessen-Targeting.
4. `App\Werbung\Verwaltung\Kampagnenname` — erzeugt den neutralen Namen samt
   Merkmal.
5. `App\Werbung\Verwaltung\Kampagnenverwaltung` — anlegen, ändern,
   pausieren, fortsetzen; lokal, mit Auftrag.
6. `Jobs\KampagneUebertragen` — Queue `default`, idempotent über das Merkmal.
7. Oberfläche: Anlegen-Dialog, Budget und Zustand je Kampagne, Zustand der
   Übertragung, Klartext bei Ablehnung.
8. Protokoll: jede Änderung an einer laufenden Kampagne ist Geld.

## Abnahmekriterien

**Prüfungen vor der Übermittlung**

1. Ein Mindestalter unter 18 ist nicht speicherbar.
2. Ein Budget unter der Untergrenze ist nicht speicherbar.
3. Interessen lassen sich nicht angeben — das Feld existiert nicht.
4. Ein Ziel außerhalb der erlaubten Liste wird abgelehnt.
5. Eine Laufzeit, die in der Vergangenheit endet, wird abgelehnt.

**Name**

6. Der Kampagnenname wird erzeugt, nicht eingegeben.
7. Er enthält keine Katalogbezeichnung — geprüft gegen alle aktiven
   Behandlungen.
8. Er trägt ein Merkmal, das ihn wiederauffindbar macht.

**Übertragung**

9. Das Anlegen erzeugt lokal eine Kampagne im Zustand *wird übertragen* und
   einen Auftrag — **kein** Aufruf im Request.
10. Nach der Antwort trägt die Kampagne Metas Kennung und den Zustand
    *übertragen*.
11. Ein zweiter Lauf desselben Auftrags legt **keine zweite** Kampagne an,
    sondern findet die erste über das Merkmal.
12. Ein Rate-Limit wird wiederholt.
13. Ein ungültiges Token wird nicht wiederholt und setzt das Werbekonto auf
    `expired`.
14. Eine fachliche Ablehnung steht im Klartext an der Kampagne und wird nicht
    wiederholt.

**Ändern**

15. Pausieren und Fortsetzen wirken lokal sofort und werden übertragen.
16. Eine Budgetänderung an einer importierten Kampagne ist möglich; eine
    Umbenennung nicht — fremde Namen ändern wir nicht.
17. Jede Änderung steht im Protokoll, mit altem und neuem Wert.

**Regeln**

18. Unter `app/Werbung` schreibt **nur** der Graphschreiber — die Liste hat
    genau einen Eintrag.
19. Zwei Mandanten können einander nichts ändern.

## Nicht in diesem Paket

- **Anzeigen, Texte, Bilder.** WP-31, und davor WP-30.
- **Die HWG-Prüfung.** WP-30.
- **Custom Audiences und Lookalikes.** C8, dauerhaft.
- **Automatische Budgetsteuerung.** Verlockend und ohne Attribution (WP-32)
  blind.
- **Löschen von Kampagnen bei Meta.** Pausieren reicht; gelöscht ist bei
  Meta nicht rückholbar, und die Attribution zeigt weiter darauf.

## Fallstricke

- **Ein wiederholter Anlegeauftrag ist ein zweites Budget.** Der teuerste
  Fehler dieses Pakets.
- **Metas Untergrenzen hängen an der Währung.** Ein fester Cent-Betrag ist
  für ein Konto in Franken falsch.
- **Pausiert ist nicht gelöscht.** Eine pausierte Kampagne kostet nichts und
  behält ihre Historie — das gehört in die Oberfläche, sonst löscht jemand.
- **`effective_status` ist nicht `status`.** Wer den falschen zeigt, meldet
  eine Kampagne als aktiv, die das Werbekonto stillgelegt hat.
- **Ein Formular, das Metas Regeln nur kennt, erzwingt sie nicht.** Die
  Prüfung gehört in den Server, nicht in die Seite.

## Stand

Die 19 Abnahmekriterien laufen: `tests/Feature/Werbung/KampagnenverwaltungTest.php`
(**20 Tests**), dazu die geänderte Schreibregel in `NurLesendTest.php`.
Gesamtstand 907 Tests, 3190 Zusicherungen. PHPStan Stufe 8 sauber, `vue-tsc`
sauber.

Neu: `SyncState`, die Spalten `sync_state`, `sync_error`, `client_token`,
`managed_by_us` an `ad_campaigns` und `ad_sets`, die Zielgruppenfelder an
`ad_sets`, `locations.meta_city_key`, `App\Werbung\Verwaltung`
(`Graphschreiber`, `Kampagnenname`, `Kampagnenplan`, `Kampagnenverwaltung`,
`Standortaufloesung`), `KampagneUebertragen`, zwei Routen und der
Anlegen-Dialog samt Pausieren/Starten je Kampagne.

`config/mrs.php` → `ads`: Mindestalter 18, Höchstalter 65, Mindestbudget je
Währung, erlaubte Ziele, Umkreisgrenzen.

**Gegen die echte Graph-API durchgelaufen.** Der Auftrag lief mit dem
erfundenen Demo-Token: Meta antwortete mit Code 190, das Werbekonto ging auf
*Unterbrochen*, die Kampagne blieb stehen. Die Fehlerstrecke ist damit nicht
nur gegen `Http::fake` geprüft. **Der Erfolgsfall ist es nicht** — dafür
braucht es `ads_management` aus dem App Review, und die Geo-Nutzlast
(`geo_locations.cities` mit Radius) gehört dort als Erstes verifiziert.

**Nachgearbeitet:** Der Anlegen-Dialog hatte *Tagesbudget (in Cent)* mit einer
`1000` darin — eine Zahl, die niemand als zehn Euro liest, und ein Tippfehler
darin kostet das Zehnfache. Jetzt Euro mit €-Zeichen im Feld, umgerechnet erst
beim Absenden; dazu drei benannte Abschnitte statt einer Feldreihe, der
Standort über die volle Breite (sein Name war abgeschnitten), das Alter als
Spanne „18 bis 65", der Umkreis mit km-Zeichen — und eine **Vorschau des
Namens**, den das Produkt vergeben wird. Gezeigt wird dabei das Muster, nicht
der fertige Name: die Zusammensetzung steht in `Kampagnenname`, und eine
zweite Fassung davon in der Seite wäre eine zweite Wahrheit.

Der erklärende Absatz am Ende sagte drei unverbundene Dinge auf einmal. Was
zur Zielgruppe gehört, steht jetzt bei der Zielgruppe; was zum Namen gehört,
beim Namen.

## Was das Bauen zutage gefördert hat

**Ein Code stand da, wo ein Satz stehen sollte.** Der erste Durchlauf gegen
Meta schrieb `token_invalid` an die Kampagne — während einen Absatz darüber
schon „Der Zugang ist abgelaufen. Bitte erneut verbinden." stand. Zweimal
dasselbe, und beim zweiten Mal unlesbar.

Daraus wurde mehr als eine Textkorrektur: **ein Verbindungsfehler ist nicht
die Schuld der Kampagne.** Die Änderung bleibt *wird übertragen*, denn sie
ist weiterhin gewollt — und beim erneuten Verbinden wird alles Wartende
nachgezogen, statt darauf zu warten, dass jemand es noch einmal eintippt. Die
Oberfläche unterscheidet jetzt *Wird übertragen* von *Wartet auf die
Verbindung*: der Unterschied entscheidet, ob jemand etwas tun muss.

**Aus der Zwangsvorgabe wurde der Mechanismus.** Der Kampagnenname muss
neutral sein (C9) und stammt deshalb vom Produkt. Weil er uns ohnehin gehört,
trägt er ein bedeutungsloses Merkmal — und das ersetzt den
Idempotenzschlüssel, den Metas Marketing-API nicht hat. Ein Auftrag, dessen
Antwort verlorenging, findet seine Kampagne daran wieder, statt eine zweite
mit zweitem Budget anzulegen. Eigener Testfall, und der teuerste Fehler, den
dieses Paket machen könnte.

**Schreiben darf nicht dieselbe Wiederholung haben wie Lesen.** Beim Lesen
ist eine Wiederholung harmlos. Beim Anlegen kann der erste Versuch geglückt
und nur die Antwort verlorengegangen sein. Der `Graphschreiber` hat deshalb
gar keine Wiederholung; wiederholt wird der **Auftrag**, und der sieht vorher
nach.

**Pest teilt Hilfsfunktionen über alle Dateien.** `werbeleitung()` in zwei
Testdateien ließ den Lauf platzen — derselbe Fallstrich wie bei `inhaberin`
in WP-06. Jetzt eine statische Methode am Aufbau statt einer globalen
Funktion.

**Ein Ort ohne Metas Kennung ist keine Zielgruppe.** Ein Umkreis lässt sich
nur um einen Ort legen, den Meta kennt. Ohne Kennung bricht das Übertragen
ab — mit einem deutschen Satz, der auf den Standort zeigt. Die Alternative
wäre eine Kampagne, die deutschlandweit ausliefert: das Geld einer Praxis in
Hamburg fiele in Passau an.

## Offen

- **`ads_management`** (WP-00). Ohne die Berechtigung läuft nur die
  Fehlerstrecke.
- **Die Geo-Nutzlast.** `geo_locations.cities` mit Radius ist die
  bestdokumentierte Kombination, aber gegen die Live-API nicht verifiziert.
  Erste Aufgabe im App Review.
- ~~**Anzeigengruppen ändern.**~~ Erledigt in **WP-27b**: Umkreis, Alter und
  Geschlecht lassen sich an einer bestehenden Gruppe ändern.
- ~~**Anzeigen.**~~ Erledigt in **WP-27b**: aus einem freigegebenen Entwurf
  mit Grafik wird ein Creative und eine Anzeige. Die Reihenfolge war richtig
  — erst WP-30, dann WP-31, dann dieser Schritt.
