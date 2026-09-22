# WP-03 · Mandantenfähigkeit & Verschlüsselung

## Ziel
Mandantentrennung und Verschlüsselung liegen so tief, dass ein späteres Paket
sie nicht versehentlich umgehen kann.

## Vorher lesen
- `docs/entscheidungen.md` — **A1** eine Datenbank mit `organization_id`; **A2** zusammengesetzte Fremdschlüssel; **A3** `TenantModel` mit Global Scope, erzwungen durch Architektur-Test; **A4** UUID v7 als `BINARY(16)`; **A5** Envelope Encryption, ein DEK je Organisation; **A6** Feldverschlüsselung plus blinde Indizes; **A11** Status als `VARCHAR` plus PHP-Enum; **D10**, **D11** Organisationsgrenze
- `docs/datenmodell.md`, Abschnitt 0
- `CLAUDE.md`, Regeln 1 und 3

## Voraussetzungen
WP-02

## Nicht nachrüstbar

Der README sagt es deutlich: Mandantentrennung und Verschlüsselung
nachträglich einzuziehen bedeutet vollständige Neuverschlüsselung aller Daten.
Alles, was ab WP-08 an Tabellen entsteht, setzt auf diesem Paket auf.

## Schritte

1. `organizations` als Mandantenwurzel. **Keine** `organization_id` auf sich
   selbst.
2. `encryption_keys`: je Organisation ein Data Encryption Key und ein
   Schlüssel für blinde Indizes, beide mit dem `APP_KEY` umschlossen
   gespeichert. Widerruf über `revoked_at`, nicht durch Löschen der Zeile.
3. `HasBinaryUuid`: UUID v7, gespeichert als `BINARY(16)`. Kanonische Form
   über das Attribut `uuid`, nicht über `id`.
4. `TenantContext`: der aktuelle Mandant, auflösbar aus dem angemeldeten
   Benutzer, aus einem Job und aus der Konsole.
5. `TenantScope` als Global Scope auf `TenantModel`. **Ohne Mandantenkontext
   wirft jede Abfrage**, sie liefert nicht etwa ein leeres Ergebnis.
6. `organization_id` wird beim Anlegen automatisch gesetzt und ist gegen
   Massenzuweisung geschützt.
7. Zusammengesetzte Fremdschlüssel `(id, organization_id)` als Hilfsmethode
   im Schema-Baukasten, damit jedes spätere Paket sie ohne Nachdenken benutzt.
8. `Encrypted`-Cast auf Basis des DEK der Organisation, AES-256-GCM.
9. Blinde Indizes als HMAC-SHA-256 über den normalisierten Wert, mit dem
   Schlüssel der Organisation.
10. Architektur-Test, der die Regeln durchsetzt statt sie zu dokumentieren.
11. Ausdrücklicher, benannter Ausstieg aus dem Scope für WP-34 — nie als
    stiller Nebeneffekt.

## Abnahmekriterien

**Trennung**

1. Eine Abfrage auf einem `TenantModel` ohne gesetzten Mandanten wirft.
2. Der Global Scope blendet Datensätze fremder Mandanten aus.
3. `find()` auf die ID eines fremden Mandanten liefert `null`, keinen Datensatz.
4. `organization_id` wird beim Anlegen automatisch gesetzt.
5. `organization_id` lässt sich nicht per Massenzuweisung setzen oder ändern.
6. Ein zusammengesetzter Fremdschlüssel verhindert einen mandantenübergreifenden
   Verweis **auf Datenbankebene**, nicht erst in der Anwendung.
7. Der Ausstieg aus dem Scope wirkt nur dort, wo er ausdrücklich steht, und
   endet mit dem Abschluss des Aufrufs — auch bei einer Ausnahme.

**Architektur-Test**
8. Ein Modell mit `organization_id`, das nicht von `TenantModel` erbt, lässt
   den Test fehlschlagen.
9. Eine Tabelle mit `organization_id` ohne zusammengesetzten Fremdschlüssel
   lässt den Test fehlschlagen.

**Schlüssel**
10. `id` liegt als `BINARY(16)` in der Datenbank.
11. Das Attribut `uuid` liefert die kanonische Form.
12. Beziehungen, Eager Loading und Factories arbeiten ohne Sonderbehandlung.
13. Die IDs zweier kurz nacheinander angelegter Datensätze sind aufsteigend
    sortierbar.

**Verschlüsselung**
14. Ein verschlüsseltes Feld steht in der Datenbank nicht im Klartext.
15. Derselbe Klartext ergibt in zwei Organisationen **verschiedene** Chiffrate.
16. Derselbe Klartext ergibt in derselben Organisation zweimal verschiedene
    Chiffrate (eigener Nonce je Schreibvorgang).
17. Ein blinder Index findet den Datensatz über den exakten Wert.
18. Derselbe Klartext ergibt in zwei Organisationen **verschiedene** blinde
    Indizes.
19. Nach Widerruf des DEK ist der Wert nicht mehr lesbar und der Fehler ist
    verständlich — keine Ausnahme aus der Krypto-Bibliothek.

## Stand

Alle Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Tenancy/MandantentrennungTest.php` | 1–7 |
| `tests/Feature/Tenancy/ArchitekturTest.php` | 8–9, plus drei Tests, die beweisen, dass die Prüfungen nicht leer durchlaufen |
| `tests/Feature/Tenancy/SchluesselTest.php` | 10–13 |
| `tests/Feature/Tenancy/VerschluesselungTest.php` | 14–19 |
| `tests/Feature/Tenancy/MandantenaufloesungTest.php` | Middleware |

Die Mechanik wird an zwei Tabellen nachgewiesen, die es nur im Testlauf gibt
(`tests/Fixtures`). WP-03 baut die Mechanik, nicht das Datenmodell — ohne echte
Tabellen ließe sich aber weder der zusammengesetzte Fremdschlüssel noch der
Architektur-Test belegen.

## Nicht in diesem Paket

Benutzer, Rollen und Einladungen (WP-04). `users.organization_id` entsteht
hier, weil der Mandant sonst nicht aus dem angemeldeten Benutzer auflösbar
wäre; alles Weitere gehört zu WP-04.

Audit-Log und Impersonation (WP-05). Der Ausstieg aus dem Scope wird hier nur
bereitgestellt, seine Protokollierung kommt dort.

Fachliche Tabellen. Hier entsteht die Mechanik, nicht das Datenmodell.

## Fallstricke

- **Ein leeres Ergebnis ist die gefährlichere Voreinstellung.** Ein fehlender
  Mandantenkontext, der still nichts liefert, sieht aus wie „keine Daten
  vorhanden" und wird als Fachfehler gesucht. Eine Ausnahme zeigt sofort auf
  die Ursache.
- **`BINARY(16)` und Eloquent vertragen sich nur in einer Richtung.** Wird das
  Attribut zur kanonischen Zeichenkette gecastet, vergleicht jede Beziehung
  eine 36-Zeichen-Zeichenkette mit 16 Bytes und findet nichts. Deshalb bleibt
  `id` intern binär, und die lesbare Form heißt `uuid`.
- **Krypto-Löschung wirkt nur, wenn die Schlüssel getrennt von den Daten
  gesichert werden.** Liegt `encryption_keys` in derselben Sicherung wie der
  Rest, stellt eine Rücksicherung den Schlüssel mit wieder her. Das ist eine
  Betriebsanforderung, keine Frage des Codes.
- **Der Global Scope schützt Eloquent, nicht den Query Builder.** Ein
  `DB::table(...)` umgeht ihn vollständig. Der Architektur-Test kann das nicht
  sehen.
- **Ein Architektur-Test, dessen Suche ins Leere greift, ist schlimmer als
  keiner** — er erzeugt Vertrauen ohne Deckung. Deshalb läuft jede Prüfung
  einmal zusätzlich ohne Zulassungsliste und muss dann die bekannten Ausnahmen
  melden.
- **`STORED` statt `VIRTUAL` bei der Wächterspalte** kostet eine Stunde
  Fehlersuche: MySQL meldet `Cannot add foreign key constraint`, obwohl der
  Fremdschlüssel in Ordnung ist. Siehe `docs/datenmodell.md`, Abschnitt 0.3.
- **Der binäre Primärschlüssel passt nicht in eine URL.** Die
  E-Mail-Bestätigung des Starter-Kits baut ihre Adresse aus `getKey()` — mit
  Rohbytes bricht sie stillschweigend. Behoben über
  `VerifyEmail::createUrlUsing()` und einen eigenen `VerifyEmailRequest`, beide
  auf `uuid`. Jede weitere Stelle, die eine ID in eine URL, ein Log oder eine
  Nutzlast schreibt, braucht dieselbe Behandlung.
