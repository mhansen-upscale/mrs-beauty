# WP-04 · Benutzer, Rollen, Einladungen

## Ziel
Ein Praxisteam arbeitet im Produkt, jeder sieht das Seine, und niemand kommt
über die Organisationsgrenze.

## Vorher lesen
- `docs/entscheidungen.md` — **A11** Status als `VARCHAR` plus PHP-Enum; **D10**, **D11** Organisationsgrenze; **C4** Impersonation (gehört zu WP-05, hier nur nicht verbauen)
- `specs/WP-03-mandantenfaehigkeit.md`, Abschnitt „Nicht in diesem Paket"
- `CLAUDE.md`, Regel 1

## Voraussetzungen
WP-03

> **Achtung, abgeleitet.** Zu diesem Paket gibt es keine Vorgabe außer einer
> Zeile im README und einer im Datenmodell. Der Rollenkatalog und der
> Zugangsweg unten sind aus den übrigen Arbeitspaketen erschlossen und
> **zu prüfen**. Sie sind so gebaut, dass eine Korrektur billig bleibt.

## Der Rollenkatalog — abgeleitet, zu prüfen

Aus den Oberflächen, die die späteren Pakete bauen:

| Rolle | Was sie tut | Hergeleitet aus |
|---|---|---|
| `owner` | Inhaberin. Alles, einschließlich Abo und Whitelabel. | WP-06, WP-07 |
| `admin` | Verwaltung: Stammdaten, Katalog, Team, Einstellungen. Kein Abo. | WP-08, WP-09 |
| `reception` | Empfang: Inbox, Termine, Warteliste, Kontakte. | WP-11, WP-21, WP-25 |
| `practitioner` | Behandlerin: der eigene Kalender, die eigenen Termine. | WP-10, WP-14 |
| `marketing` | Kampagnen, Anzeigen, Auswertung, Brand Guide, HWG-Prüfung. | WP-26 bis WP-32 |

**Behandlerin und Benutzerkonto sind nicht dasselbe.** Eine Praxis führt
Behandler im Kalender, die sich nie anmelden (WP-08). Die Verbindung entsteht
dort über ein optionales `practitioners.user_id`, nicht hier.

**Feste Rollen, keine frei zusammenstellbaren Rechte.** Eine Praxis mit fünf
Personen braucht keinen Rechteeditor, und ein solcher Editor ist der
zuverlässigste Weg, versehentlich zu viel zu vergeben. Fähigkeiten hängen am
Rollen-Enum, nicht in der Datenbank.

## Der Zugangsweg — abgeleitet, zu prüfen

`docs/produkt.md` führt „Onboarding einer neuen Praxis" ausdrücklich als
ungeklärt. Deshalb sind **beide** Wege gebaut und einer ist ein Schalter:

| `mrs.registration.self_service` | Wirkung |
|---|---|
| `false` (**Standard**) | Keine offene Registrierung. Praxen werden angelegt, das Team kommt über Einladungen herein. |
| `true` | Offene Registrierung legt Organisation **und** `owner` an. |

Der Standard ist bewusst die geschlossene Variante: Ein Produkt, das
HWG-Prüfungen für Ärzte ausspricht, will nicht, dass sich Beliebige eine
Praxis anlegen.

## Schritte

1. `Role` als `string`-Enum, in `users.role` als `VARCHAR` (Entscheidung A11).
2. `Ability` als `string`-Enum. Die Zuordnung Rolle → Fähigkeiten steht im
   Enum, nicht in der Datenbank.
3. Alle Fähigkeiten als Gates registrieren, damit `$user->can('…')`,
   `@can` und `Gate::authorize()` überall gleich funktionieren.
4. `users.deactivated_at`. Eine deaktivierte Person kann sich nicht anmelden
   und wird aus bestehenden Sitzungen geworfen.
5. `invitations` als `TenantModel`: E-Mail, Rolle, gehashtes Merkmal,
   Ablauf, Einladende, Annahme, Widerruf.
6. Einladungsstrecke: verschicken, annehmen, ablehnen, erneut schicken,
   widerrufen.
7. Mitgliederverwaltung: Liste, Rolle ändern, deaktivieren, reaktivieren.
8. Registrierung hinter den Schalter legen; im offenen Fall Organisation,
   Schlüsselsatz und `owner` in **einer** Transaktion anlegen.

## Abnahmekriterien

**Rollen und Fähigkeiten**
1. Jede Rolle hat genau die Fähigkeiten, die der Katalog nennt.
2. `owner` hat jede Fähigkeit.
3. Eine Person ohne die Fähigkeit bekommt 403, nicht 404 und nicht 500.
4. Ein neuer `Ability`-Fall ohne Zuordnung zu einer Rolle lässt einen Test
   fehlschlagen — vergessene Rechte fallen auf, statt still zu wirken.

**Mandantengrenze**
5. Die Mitgliederliste zeigt ausschließlich Personen der eigenen Organisation.
6. Eine Rollenänderung an einer Person einer fremden Organisation ist nicht
   möglich.
7. Die letzte `owner` einer Organisation lässt sich weder herabstufen noch
   deaktivieren.

**Deaktivierung**
8. Eine deaktivierte Person kann sich nicht anmelden.
9. Eine Person, die während ihrer Sitzung deaktiviert wird, ist beim nächsten
   Aufruf abgemeldet.

**Einladungen**
10. Eine Einladung landet in der Organisation der einladenden Person.
11. Das Merkmal steht nur im Versand, in der Datenbank liegt ein Hash.
12. Eine angenommene Einladung legt die Person mit der eingeladenen Rolle an.
13. Eine abgelaufene Einladung lässt sich nicht annehmen.
14. Eine widerrufene Einladung lässt sich nicht annehmen.
15. Eine bereits angenommene Einladung lässt sich nicht erneut annehmen.
16. Eine Einladung an eine bereits vorhandene E-Mail-Adresse der Organisation
    ist nicht möglich.
17. Die Annahme setzt die E-Mail-Adresse als bestätigt — der Weg über das
    Postfach ist der Nachweis.

**Registrierung**
18. Bei `self_service = false` ist die Registrierung nicht erreichbar.
19. Bei `self_service = true` entstehen Organisation, Schlüsselsatz und
    `owner` gemeinsam; schlägt ein Schritt fehl, entsteht keiner davon.

## Stand

Alle 19 Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Team/RollenTest.php` | 1–4 |
| `tests/Feature/Team/MitgliederTest.php` | 5–9 |
| `tests/Feature/Team/EinladungenTest.php` | 10–17 |
| `tests/Feature/Team/RegistrierungTest.php` | 18–19 |

Zusätzlich entstanden:

- `ui/select` als shadcn-vue-Bauteil (Entscheidung S3, siehe
  `docs/konventionen.md` — die CLI ist auf `reka-ui` gewechselt und nicht mehr
  benutzbar).
- `tests/Feature/Schema/RohbytesTest.php` — siehe unten.
- Ein Seeder mit einer Demo-Praxis und je einem Zugang pro Rolle.

## Nicht in diesem Paket

Audit-Log und Impersonation (WP-05). Hier wird nur nichts verbaut, was dort
gebraucht wird.

Abo und Abrechnung (WP-06). `owner` bekommt die Fähigkeit bereits, die
Oberfläche dazu entsteht dort.

Behandler als Stammdaten (WP-08).

Ein Rechteeditor. Sollte er je gebraucht werden, ist er ein eigenes Paket.

## Ein Fund, der über dieses Paket hinausgeht

Die Teamliste brach mit „Not a valid Inertia response". Ursache war nicht die
Liste: **`organization_id` wurde als Rohbytes serialisiert und ließ
`json_encode()` scheitern** — in *jeder* Antwort, die `auth.user` teilt, also
überall.

Das war eine offene Flanke aus WP-03, die dort kein Test getroffen hatte.
Behoben, und mit einer ausführbaren Regel abgesichert
(`tests/Feature/Schema/RohbytesTest.php`): Eine binäre Spalte ist entweder
verborgen oder gecastet. `BelongsToTenant` und `HasBlindIndexes` verbergen ihre
Spalten jetzt selbst, damit das nicht an jedem neuen Modell wiederholt werden
muss.

## Fallstricke

- **`users` ist kein `TenantModel`** (siehe WP-03). Der Global Scope schützt
  hier niemanden — jede Abfrage auf Benutzer muss die Organisation selbst
  einschränken. Das ist die wahrscheinlichste Stelle für ein Mandantenleck in
  diesem Paket.
- **Die letzte Inhaberin muss bleiben.** Ohne diese Sperre sperrt sich eine
  Praxis aus ihrem eigenen Produkt aus, und niemand außer dem Super-Admin
  kommt wieder hinein.
- **Das Einladungsmerkmal gehört nicht in die Datenbank.** Wer die Datenbank
  liest, könnte sonst jede offene Einladung annehmen.
- **Eine Deaktivierung, die nur die Anmeldung sperrt, wirkt nicht.** Eine
  bestehende Sitzung läuft sonst weiter, bis sie abläuft. Deshalb das
  `EnsureUserIsActive`-Middleware.
- **`Model::is()` ist von Eloquent belegt** (Vergleich zweier Modelle). Die
  Rollenprüfung heißt deshalb `hasRole()`. Ein Überschreiben bricht mit einem
  Fatal Error beim ersten Aufruf.
- **Enums taugen nicht als Array-Schlüssel.** Weder im Seeder noch in
  `array_diff()` — dort wird in Zeichenketten umgewandelt, was ein Enum nicht
  kann. Über `->value` gehen.
- **`DROP TABLE` beendet in MySQL die Transaktion des Tests.** Wer einen
  Rollback prüfen will, erzwingt den Fehler im Code, nicht über DDL.
