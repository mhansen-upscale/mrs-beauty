# WP-05 · Audit-Log & Impersonation

## Ziel
Jeder Zugriff, der einer Erklärung bedarf, ist nachträglich erklärbar — ohne
dass das Protokoll selbst zum Datenschutzproblem wird.

## Vorher lesen
- `docs/entscheidungen.md` — **C4** Impersonation standardmäßig maskiert, Vollzugriff nur mit Freigabe des Kunden, befristet, protokolliert; **C5** Audit-Log append-only, ohne Klartext-Personendaten; **C7** Aufbewahrung 36 Monate
- `specs/WP-03-mandantenfaehigkeit.md`, Abschnitt „Nicht in diesem Paket" — `acrossTenants()` wartet hier auf seine Protokollierung
- `CLAUDE.md`, Regeln 1 und 3

## Voraussetzungen
WP-03, WP-04

## Warum das Protokoll selbst gefährlich ist

Ein Audit-Log ist die eine Tabelle, die **alles** sieht. Schreibt es Namen,
Adressen oder Nachrichteninhalte im Klartext mit, entsteht neben der
verschlüsselten Datenhaltung eine zweite, unverschlüsselte Kopie derselben
Daten — und zwar die, auf die am längsten niemand schaut.

Entscheidung C5 zieht daraus zwei Konsequenzen:

1. **Ohne Klartext-Personendaten.** Protokolliert wird, *wer* *wann* *was* an
   *welchem Datensatz* getan hat — nicht der Inhalt. Geänderte Felder werden
   namentlich genannt, ihre Werte nicht.
2. **Append-only.** Ein Protokoll, das sich ändern lässt, ist keines. Die
   Sperre gehört in die Datenbank, nicht in eine Klasse: wer mit einem
   Datenbankwerkzeug herangeht, umgeht Eloquent.

## Schritte

1. `audit_logs` als `TenantModel`, zusätzlich für mandantenübergreifende
   Vorgänge nutzbar (Super-Admin).
2. **Append-only auf Datenbankebene**: Trigger, die `UPDATE` und `DELETE`
   abweisen. Dazu eine Sperre im Modell, damit der Fehler früh und
   verständlich kommt.
3. `AuditEvent` als Enum. Kein Freitext — ein Protokoll, dessen Ereignisnamen
   sich je Aufrufstelle unterscheiden, ist nicht auswertbar.
4. `Auditable`-Trait: protokolliert Anlegen, Ändern und Löschen eines Modells
   mit **Feldnamen statt Werten**. Ein Modell darf einzelne Felder als
   unbedenklich erklären; die Vorgabe ist, nichts zu protokollieren.
5. **`acrossTenants()` protokolliert sich selbst** — der einzige Weg an der
   Mandantentrennung vorbei (WP-03) darf nicht unbemerkt benutzbar sein.
6. `impersonation_sessions`: Modus `masked` oder `full`, Begründung,
   Freigabe, Ablauf, Ende.
7. **Maskierung als Vorgabe.** Eine Maskierungsschicht, die personenbezogene
   Werte ersetzt, solange eine Sitzung im Modus `masked` läuft.
8. **Vollzugriff nur nach Freigabe** durch eine Inhaberin des betroffenen
   Mandanten, immer befristet.
9. Deutlich sichtbarer Hinweis in der Oberfläche, solange eine Impersonation
   läuft, mit Ausstieg in einem Klick.
10. Protokollansicht für Inhaberin und Verwaltung — auf die eigene
    Organisation beschränkt.

## Abnahmekriterien

**Append-only**

1. Ein Protokolleintrag lässt sich über Eloquent nicht ändern.
2. Ein Protokolleintrag lässt sich über Eloquent nicht löschen.
3. Ein `UPDATE` **direkt auf der Datenbank** schlägt fehl.
4. Ein `DELETE` **direkt auf der Datenbank** schlägt fehl.

**Keine Klartext-Personendaten**

5. Ein protokolliertes Anlegen nennt die geänderten Felder, nicht deren Werte.
6. Ein als unbedenklich erklärtes Feld darf seinen Wert mitführen.
7. Ein Test durchsucht alle Protokolleinträge eines Durchlaufs auf die
   Klartextwerte, die im Test gesetzt wurden, und findet keinen.

**Mandantengrenze**

8. Die Protokollansicht zeigt nur Einträge der eigenen Organisation.
9. `acrossTenants()` erzeugt einen Protokolleintrag mit Begründung.
10. Ein Aufruf ohne Begründung ist nicht möglich.

**Impersonation**

11. Eine Impersonation ohne Super-Admin-Recht ist nicht möglich.
12. Eine neue Sitzung startet im Modus `masked`.
13. Im Modus `masked` sind personenbezogene Werte in der Antwort ersetzt.
14. Vollzugriff ohne Freigabe ist nicht möglich.
15. Die Freigabe erteilt nur eine Inhaberin des betroffenen Mandanten.
16. Eine abgelaufene Sitzung wirkt nicht mehr, auch ohne Aufräumjob.
17. Start, Freigabe, Ende und Ablauf stehen im Protokoll.
18. Solange eine Impersonation läuft, ist sie in jeder Antwort erkennbar.
19. Die Person, die impersoniert wird, sieht die Vorgänge in ihrem eigenen
    Protokoll.

## Stand

Alle 19 Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Audit/ProtokollTest.php` | 1–10 |
| `tests/Feature/Audit/ImpersonationTest.php` | 11–19, plus vier Fälle, die beim Bauen dazukamen |
| `tests/Feature/Audit/DeckungTest.php` | zwei Regeln, siehe unten |

**Die Abwägung zum Fallstrick „Das Protokoll darf den Vorgang nicht
verhindern" ist getroffen:** `AuditLogger` fängt jeden Fehler, schreibt ihn
ins Anwendungslog und lässt den Vorgang durchlaufen. Ein Praxisteam, dem ein
Termin an einem vollen Protokolldatenträger scheitert, verliert das Vertrauen
schneller als an einer Lücke im Protokoll.

## Was das Bauen zutage gefördert hat

**Der Support sah während der Impersonation nichts.** Fähigkeiten hängen an
der Rolle, und ein Super-Admin hat in einer fremden Organisation keine. Jetzt
bekommt er während einer Sitzung die Sicht einer Inhaberin — **abzüglich zwei
Fähigkeiten**: Abo und, entscheidend, die **Freigabe des Vollzugriffs**. Wer
sich selbst freigeben könnte, hätte Entscheidung C4 ausgehebelt. Das ist der
Kern des Ganzen und hat jetzt einen eigenen Test.

**Die Maskierung hing an der falschen Stelle.** Zuerst saß sie in
`attributesToArray()`. Der erste Controller, der sein Array von Hand baute
(`'name' => $mitglied->name`), lieferte prompt die Klarnamen aus — genau der
Fallstrick, der oben im Briefing steht, und ich bin trotzdem hineingelaufen.
Sie sitzt jetzt am Attributzugriff und greift auf **jedem** Lesepfad.
Schreiben auf ein maskiertes Feld wirft, sonst landet der Platzhalter in der
Datenbank, weil ein Formular ihn brav zurückgeschickt hat.

**Die Platzhalter waren alle gleich.** `Maskiert 01A0`, fünfmal. UUID v7 ist
zeitsortiert, also sind die vorderen Bytes bei kurz nacheinander angelegten
Zeilen identisch. Jetzt stammen die vier Stellen aus dem Zufallsende — sonst
kann eine Supportkraft nicht sagen, welche Zeile sie meint, und das war der
einzige Zweck des Anhängsels.

**Der Global Scope lässt sich mit Eloquents eigener API abstreifen.**
`withoutGlobalScope()` umgeht die Mandantentrennung, ohne einen
Protokolleintrag zu erzeugen — eine Lücke in dem, was WP-03 zugesichert hat.
Im Anwendungscode jetzt untersagt, durchgesetzt durch einen Test. In Tests
bleibt es erlaubt, dort wäre die Protokollierung nur Rauschen.

**Zwei ausführbare Regeln kamen dazu** (`DeckungTest.php`): kein
`withoutGlobalScope` in `app/`, und jedes Modell mit einem `Encrypted`-Cast
muss seine personenbezogenen Felder benennen — verschlüsselt heißt
personenbezogen, sonst wäre die Verschlüsselung sinnlos. Die zweite Regel griff
sofort bei einem Modell aus WP-03.

## Nicht in diesem Paket

Das Super-Admin-Backoffice (WP-34). Hier entsteht das Kennzeichen am Benutzer
und die Mechanik; die Oberfläche zur Mandantenauswahl kommt dort. **WP-34 kann
dieses Paket aushebeln** — das ist im README so vermerkt und bleibt beim Bau
des Backoffice zu beachten.

Der Aufräumjob für die 36 Monate (Entscheidung C7). Die Frist steht in
`config/mrs.php`, der Job gehört zu WP-18.

Protokollierung fachlicher Vorgänge, die es noch nicht gibt — Termine,
Nachrichten, Kampagnen. Die Pakete hängen sich später an `Auditable`.

## Fallstricke

- **Ein Protokoll mit Klartext ist eine zweite Datenhaltung.** Und zwar die
  ohne Verschlüsselung, ohne Aufbewahrungsgrenze im Blick und ohne
  Löschkonzept. Deshalb ist die Vorgabe, **nichts** mitzuschreiben, und die
  Ausnahme muss je Feld erklärt werden.
- **Append-only gehört in die Datenbank.** Eine Sperre im Modell schützt vor
  dem eigenen Code, nicht vor einem Datenbankwerkzeug und nicht vor einem
  `DB::table('audit_logs')->update(...)`.
- **Maskierung, die man vergessen kann, ist keine.** Sie darf nicht an jeder
  Aufrufstelle einzeln stehen, sondern muss dort greifen, wo die Werte das
  System verlassen.
- **Eine abgelaufene Sitzung muss sofort wirkungslos sein**, nicht erst, wenn
  ein Job sie aufräumt. Jede Prüfung vergleicht gegen die Uhr.
- **Das Protokoll darf den Vorgang nicht verhindern.** Schlägt das Schreiben
  fehl, ist das ein Alarm — aber keine Ausnahme, die dem Praxisteam den
  Termin zerschießt. Die Abwägung gehört ausdrücklich getroffen und steht
  unten im Stand.
