# WP-15 · Kalendersync Microsoft

## Ziel
Ein Behandler mit Outlook-Kalender bekommt dasselbe wie einer mit Google —
und danach steht hinter beiden **eine** Mechanik statt zweier.

## Vorher lesen
- `docs/integrationen/kalender.md` — R1 bis R4 und vor allem die Tabelle
  „Unterschiede"; dazu der Satz **„Erst beide umsetzen, dann abstrahieren."**
- `specs/WP-14-kalendersync-google.md` — was steht, was es zutage gefördert
  hat, was offen blieb
- `docs/entscheidungen.md` — **B4**, **B5**, **B6**, **A13**, **A14**
- `CLAUDE.md`, Regeln 3 und 4

## Voraussetzungen
WP-14

## Das Paket hat zwei Hälften, und die Reihenfolge ist Vorschrift

**Erst Microsoft bauen, dann abstrahieren.** `docs/integrationen/kalender.md`
ist an der Stelle unmissverständlich: *„Ein gemeinsames Interface vor der
zweiten Umsetzung zu bauen führt zu einer Abstraktion, die auf keinen von
beiden richtig passt."*

WP-14 hat deshalb bewusst kein Interface hinterlassen. Die zweite Hälfte
dieses Pakets zieht es ein — **nachdem** beide Anbieter funktionieren und
sichtbar ist, was sie wirklich teilen und was nur zufällig ähnlich aussieht.

## Was das Schema schon kann

`calendar_connections` braucht **keine** Änderung, und das ist der erste
Beleg, dass der Schnitt aus WP-14 getragen hat:

| Spalte | Google | Microsoft |
|---|---|---|
| `channel_id` | selbst erzeugte Kanalkennung | Abonnement-ID von Graph |
| `channel_token` | selbst vergeben, kommt gespiegelt zurück | `clientState`, kommt gespiegelt zurück |
| `channel_resource_id` | `resourceId` zum Abbestellen | Ressourcenpfad |
| `sync_token` | `nextSyncToken` | `@odata.deltaLink` |
| `calendar_timezone` | Zone des Kalenders | Zone des Postfachs |

`CalendarProvider` bekommt einen Fall, keine Tabelle — wie bei
`NotificationChannel` in WP-13.

## Wo Microsoft wirklich anders ist

Nicht „dasselbe mit anderen Feldnamen". Fünf Unterschiede haben eigene Tests,
weil sie sich nicht aus dem Google-Weg ergeben:

**Zeitangaben tragen keinen Versatz.** Graph liefert
`{"dateTime": "2027-01-13T09:00:00.0000000", "timeZone": "UTC"}` — die
Zeichenkette allein ist mehrdeutig. Wer sie ohne das Feld `timeZone` liest,
bekommt je nach Serverzone einen Blocker, der Stunden danebenliegt. Google
lieferte RFC 3339 mit Versatz; dort war die Umrechnung eindeutig. **Das ist
der gefährlichste Unterschied der beiden Anbieter.**

**Ganztägig ist ein Kennzeichen, keine andere Feldform.** `isAllDay: true`,
und Beginn und Ende stehen als Mitternacht in der Zone des Kalenders — das
Ende wieder ausschließend. Dieselbe Bedeutung wie Googles `date`, andere
Darstellung.

**Die Eigenmarkierung ist eine Open Extension.** Kein Feld am Event, sondern
ein angehängtes Objekt mit eigenem Namen. Ausgehend lässt es sich beim
Anlegen mitgeben; **eingehend kommt es nur mit, wenn man es ausdrücklich
anfordert** (`$expand=extensions(...)`). Wer das vergisst, liest die eigene
Marke nie — und baut damit genau die Schleife, gegen die R1 gedacht ist.
Nicht auffällig, weil alles andere funktioniert.

**Das Abonnement lebt keine drei Tage** statt dreißig, lässt sich dafür
verlängern statt neu bestellen. Der Erneuerungsjob aus WP-14 läuft stündlich
— das reicht, aber `renew_before_expiry_hours` von 24 Stunden ist für
Microsoft zu knapp bemessen und gehört je Anbieter gesetzt.

**Die Zustellung hat einen Handschlag.** Beim Anlegen des Abonnements ruft
Graph die Adresse sofort auf und erwartet den mitgegebenen `validationToken`
binnen zehn Sekunden **als reinen Text** zurück. Ohne diese Antwort entsteht
das Abonnement gar nicht erst. Google kennt nichts dergleichen.

**Ein verfallenes Delta-Token** meldet Graph ebenfalls mit `410`, aber mit
eigenem Fehlercode (`resyncRequired`, `syncStateNotFound`). Behandelt wird es
wie bei Google: kein Fehler, sondern der Weg zum Vollabgleich.

## Die Abstraktion — was zusammengeht und was nicht

Erst nach der Umsetzung, und nur für das, was sich als wirklich gemeinsam
herausgestellt hat:

| Bleibt gemeinsam | Bleibt getrennt |
|---|---|
| `Rueckabgleich` — Reihenfolge der Prüfungen, R1 zuerst | Die Abfrage selbst |
| `Blockerabgleich` — R3 auf Slot-Zeilen | Das Lesen eines Events |
| `Terminkalender`, die Aufträge, der Idempotenzschlüssel | Der Aufbau der Nutzlast |
| `Abonnements` — erst das neue, dann das alte beenden | Bestellen, Verlängern, Beenden |
| Zustand, Ausfall, Sichtbarkeit im Produkt (R4) | OAuth-Adressen und Fehlercodes |

Der Schnitt läuft damit **an der Nutzlast**, nicht an der Fachlogik: ein
Anbieter liefert `Ereignis`-Objekte und nimmt `Termindaten` entgegen. Was
dazwischen passiert, gibt es genau einmal.

## Schritte

**Erste Hälfte — Microsoft**

1. `CalendarProvider::Microsoft`, `config/services.php`, Erneuerungsvorlauf
   je Anbieter in `config/mrs.php`.
2. `MicrosoftZugang` — OAuth v2, `offline_access` für den
   Aktualisierungsschlüssel.
3. `MicrosoftKalender` — Delta über `calendarView/delta`, Open Extensions,
   Abonnements samt Verlängerung.
4. `Microsoft\Ereignis` — `dateTime` **plus** `timeZone`, `isAllDay`,
   `showAs`, `@removed`.
5. Zustellung mit Handschlag: `validationToken` als `text/plain` zurück.
6. Rückkehrweg `/oauth/microsoft/callback`, Verbinden-Knopf je Anbieter.

**Zweite Hälfte — zusammenlegen**

7. `Kalenderdienst` und `Kalenderzugang` als Schnittstellen, eine Fabrik über
   `CalendarProvider`.
8. `Ausgangsereignis` liefert `Termindaten`; jeder Anbieter serialisiert
   selbst.
9. `Rueckabgleich`, `Abonnements`, `Terminkalender` und die Aufträge kennen
   nur noch die Schnittstelle.
10. Die Tests aus WP-14 laufen unverändert weiter — sonst war es keine
    Abstraktion, sondern ein Umbau.

## Abnahmekriterien

**Verbindung**

1. Der Rückweg legt eine Microsoft-Verbindung an, Token verschlüsselt.
2. Ein Behandler kann Google **und** Microsoft verbunden haben.
3. Zwei Anbieter erzeugen für dieselbe Zeit **keine** doppelten Blocker.

**Zeit — der gefährlichste Unterschied**

4. Ein Event wird in der mitgelieferten `timeZone` ausgewertet, nicht in der
   des Servers.
5. Dasselbe Event in zwei Zonen ergibt denselben UTC-Zeitraum.
6. Ein ganztägiges Event deckt den Arbeitstag der Kalenderzone ab.

**Eigenmarkierung (R1, B5)**

7. Ein ausgehendes Event trägt eine Open Extension mit Organisation und
   Termin.
8. Der Rücksync fordert die Erweiterungen ausdrücklich an.
9. Ein eigenmarkiertes Event erzeugt keinen Blocker.
10. Das markierte Event einer anderen Praxis erzeugt sehr wohl einen.

**Datensparsamkeit (R2, Regel 3)**

11. Der Titel des ausgehenden Events nennt weder Kontakt noch Behandlung.
12. Der Originaltitel steht in keiner Spalte.

**Deltas**

13. Ein Delta merkt sich den `deltaLink`.
14. `410` mit `resyncRequired` löst einen Vollabgleich aus, kein Fehler.
15. Ein `@removed`-Eintrag entfernt seinen Blocker.
16. `showAs: free` erzeugt keinen Blocker.

**Zustellung (A14)**

17. Der Handschlag antwortet mit dem `validationToken` als reinem Text.
18. Eine Zustellung mit falschem `clientState` löst nichts aus.
19. Der Empfang quittiert sofort und verarbeitet asynchron.
20. Dieselbe Zustellung zweimal erzeugt einen Lauf, nicht zwei.

**Abonnement (R4)**

21. Ein Abonnement wird **verlängert**, nicht neu bestellt.
22. Der Vorlauf ist je Anbieter gesetzt und für Microsoft kürzer.
23. Ein entzogener Zugang setzt die Verbindung auf `expired`.

**Abstraktion**

24. Alle Abnahmekriterien aus WP-14 laufen unverändert weiter.
25. `Rueckabgleich`, `Blockerabgleich`, `Terminkalender` und die Aufträge
    nennen keinen Anbieter beim Namen — ausführbar geprüft.
26. Ein neuer Anbieter braucht keine Änderung an der Fachlogik.

## Nicht in diesem Paket

**Ein dritter Anbieter.** Die Abstraktion entsteht aus zwei belegten Fällen,
nicht aus der Vorstellung eines dritten.

**Geteilte Postfächer und Räume.** Ein Behandler, ein Kalender.

**Wiederkehrende Termine mit abweichenden Einzelinstanzen** — `calendarView`
löst die Serie auf, wie `singleEvents` bei Google. Die eigene Testklasse, die
der Leitfaden verlangt, bleibt offen und gehört zu dem Paket, das
Serientermine im Produkt einführt.

## Fallstricke

- **`dateTime` ohne `timeZone` gelesen** ist der teuerste Fehler dieses
  Pakets: er erzeugt Blocker, die still danebenliegen.
- **Open Extensions kommen nicht von allein mit.** Ohne `$expand` liest man
  die eigene Marke nie und blockiert die eigenen Termine.
- **Der Handschlag hat zehn Sekunden.** Keine Datenbankarbeit davor.
- **Das Abonnement lebt Stunden, nicht Wochen.** Ein Vorlauf, der für Google
  passt, ist hier zu knapp.
- **Abstrahieren heißt nicht vereinheitlichen.** Was sich nur ähnlich sieht,
  bleibt getrennt — sonst entsteht die Abstraktion, vor der der Leitfaden
  warnt.

## Stand

Alle 26 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Kalender/` — **79 Tests**, davon 51 aus WP-14 unverändert
(Kriterium 24), 24 neue für Microsoft und 4 für die Abstraktion selbst.

Gegenstelle ist `Tests\Feature\Kalender\Graphattrappe`, Schwester der
`Googleattrappe`. Beide antworten **nur auf ihren eigenen Host** und geben
sonst `null` zurück — erst dadurch lassen sich zwei Anbieter in einem Test
nebeneinander stellen.

Die Seite „Kalender" zeigt jetzt eine Zeile je Behandler **und** Anbieter.
`mrs:kalender-abgleichen` und `mrs:kalender-abos-erneuern` bedienen beide
Anbieter über denselben Weg.

### Was zusammengegangen ist

| | |
|---|---|
| Gemeinsam (`App\Kalender`) | `Rueckabgleich`, `Blockerabgleich`, `Terminkalender`, `Abonnements`, `Eigenmarkierung`, `Termineinladung`, alle Aufträge, die Wertobjekte `Ereignis`, `Ereignisseite`, `Abonnement`, `Kalenderangaben`, `Zugangsdaten` |
| Je Anbieter | HTTP-Zugriff, OAuth, `Ereignisleser`, `Ausgangsereignis`, der Empfang der Zustellung |

`Kalenderdienst` hat zwölf Methoden. Was **nicht** darin steht, ist die
eigentliche Aussage: keine Fehlercodes, keine Adressen, keine Rechtenamen,
keine Höchstlaufzeiten. Das sind keine gemeinsamen Begriffe, sondern zufällig
ähnlich aussehende Eigenheiten — sie aufzunehmen hätte genau die Abstraktion
ergeben, vor der der Leitfaden warnt.

**Das Schema brauchte keine Änderung.** `calendar_connections` trägt beide
Anbieter unverändert. Das ist der beste verfügbare Beleg, dass der Zuschnitt
aus WP-14 getragen hat.

## Was das Bauen zutage gefördert hat

**`data_get()` liest den Punkt als Pfad.** Graph nennt seinen Delta-Zeiger
`@odata.deltaLink`; `data_get($daten, '@odata.deltaLink')` sucht darin ein
Feld `deltaLink` unter `@odata`, findet nichts und wirft nichts. Die Folge
wäre ein Sync gewesen, der bei **jedem** Lauf einen Vollabgleich macht — ohne
dass irgendetwas fehlschlägt, nur langsamer und teurer. Gefunden vom Test, der
prüft, dass der zweite Lauf ein Delta ist.

**Zwei Attrappen antworten auf alles.** Die Googleattrappe hatte einen
Auffangzweig („alles Übrige ist die Abfrage des Kalenders"), und Laravel
nimmt die erste Attrappe, die etwas liefert. Der Test „zwei Anbieter erzeugen
keine doppelten Blocker" lief damit grün, ohne je eine Graph-Anfrage zu
sehen. Beide Attrappen begrenzen sich jetzt auf ihren Host.

**Die Delta-Abfrage von Graph trägt keine Erweiterungen.** Die
Eigenmarkierung (R1) ist auf diesem Weg gar nicht lesbar — der Schutz gegen
die Endlosschleife hätte für Microsoft schlicht nicht existiert, und zwar
lautlos. R1 hat deshalb jetzt **zwei Hälften**: die Marke am Event und der
Abgleich gegen die Kennungen aus `calendar_event_links`. Die zweite ist
anbieterunabhängig und stärkt Google mit.

Sie hat prompt einen Testfall entlarvt: „ein externer Blocker über einem
Termin" benutzte in WP-14 die Kennung `extern-1` — genau die, die unser
eigener Eintrag drüben bekommt. Der Test prüfte damit ab sofort die
Eigenmarkierung statt der Konfliktregel. Jetzt heißt das fremde Event
`fremd-1`.

**Microsoft dreht den Aktualisierungsschlüssel mit.** Bei jeder Erneuerung
kommt ein neuer, der alte verfällt. Google schickt ihn nur beim ersten Mal.
Wer den einen Fall auf den anderen überträgt, hat eine Verbindung, die genau
einmal funktioniert — in beide Richtungen ein stiller Ausfall.

**Ein Rückweg gehört zu dem Anbieter, bei dem er losging.** Der `state` trägt
den Anbieter jetzt mit; sonst ließe sich ein Google-Code am
Microsoft-Endpunkt einlösen.

## Offen

**Gegen die echte Graph-API geprüft ist nichts.** WP-14 ist inzwischen mit
echten Google-Zugangsdaten gelaufen; für Microsoft steht das aus und braucht
eine Azure-App-Registrierung. Zwei Stellen würde ich dabei zuerst ansehen:
die Zonennamen (Graph liefert gelegentlich Windows-Schreibweise — der
Rückfall auf die Kalenderzone ist gebaut und getestet, aber nicht belegt) und
die tatsächliche Höchstlaufzeit eines Abonnements.

**Wiederkehrende Termine mit abweichenden Einzelinstanzen** — beide Anbieter
lösen die Serie heute selbst auf (`singleEvents`, `calendarView`). Die eigene
Testklasse, die `docs/integrationen/kalender.md` verlangt, gehört zu dem
Paket, das Serientermine im Produkt einführt.

**Geteilte Postfächer und Räume** bleiben außen vor: ein Behandler, ein
Kalender.
