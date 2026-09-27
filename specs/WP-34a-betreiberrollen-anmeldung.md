# WP-34a · Betreiberrollen & Anmeldung

> Nachtrag zu WP-34. Das Backoffice steht, aber es kennt nur ein Kennzeichen
> (`users.is_super_admin`): wer Betreiber ist, darf alles. Das Team des
> Betreibers besteht aus Super-Admins, Customer Success und Finanzen, und die
> sollen nicht dasselbe dürfen.

## Ziel
Jede Person im Team des Betreibers meldet sich an einem eigenen Eingang an
und darf genau das, was ihre Rolle braucht. Eine gesperrte Praxis kommt
tatsächlich nicht mehr hinein.

## Vorher lesen
- `docs/entscheidungen.md`: **C14** (dieses Paket), **C4** Impersonation,
  **C5** kein Klartext im Protokoll, **C7** Aufbewahrung, **A11** Status als
  `VARCHAR` plus Enum
- `CLAUDE.md`, **Regel 1**
- `specs/WP-34-backoffice.md`, vollständig, besonders „Was das Bauen zutage
  gefördert hat"
- `specs/WP-05-audit-log-impersonation.md`, Abschnitte *Impersonation* und
  „Der Support sah während der Impersonation nichts"
- `specs/WP-04-benutzer-rollen-einladungen.md`, *Der Rollenkatalog*. Das
  Muster „Fähigkeiten im Enum, nicht in der Datenbank" gilt hier genauso.

## Voraussetzungen
WP-04, WP-05, WP-34.

## Die Linie, an der alles hängt

**Ein Betreiber gehört zu keiner Praxis. Was er darf, hängt an seiner
Betreiberrolle, nie an einer Praxisrolle.**

Praxisrollen (`Role`) und Betreiberrollen (`OperatorRole`) sind zwei
getrennte Welten. Das ist kein Abstufungs-, sondern ein
Grundsatzunterschied, wie schon der Kommentar in `routes/backoffice.php`
sagt. Ein Betreiber bekommt Praxisfähigkeiten ausschließlich während einer
Impersonation, und dann abzüglich Abo und Freigabe (WP-05).

Die Datenbank hält das fest, nicht die Disziplin am Aufrufort: Ein Konto mit
Betreiberrolle hat keine `organization_id` und keine `role`.

## Der Rollenkatalog, abgeleitet und zu prüfen

Festgelegt sind die drei Rollen (C14). Die Zuordnung der Fähigkeiten ist aus
dem Gespräch vom 27.09.2026 abgeleitet und **zu bestätigen**. Wie in WP-04
steht sie im Enum, damit eine Korrektur eine Zeile kostet.

| Fähigkeit (`OperatorAbility`) | Super-Admin | Customer Success | Finanzen |
|---|---|---|---|
| `mandanten.sehen`: Liste, Mandantenblatt, Betriebslage | ✓ | ✓ | ✓ |
| `abo.sehen`: Abo-Zustand, Fristen, Eingriffe | ✓ | ✓ | ✓ |
| `kontingent.gutschreiben` | ✓ | ✓ | – |
| `testphase.verlaengern` (WP-34c) | ✓ | ✓ | – |
| `support.zugriff`: Impersonation starten, PIN einlösen (WP-34b) | ✓ | ✓ | – |
| `mandanten.sperren`: sperren, entsperren | ✓ | – | – |
| `abo.eingreifen`: pausieren, kündigen, Gratismonat (WP-34c) | ✓ | – | – |
| `paket.verwalten`: Name, Preise, Kontingente des Pakets (WP-06b) | ✓ | – | – |
| `finanzen.sehen` (WP-34d) | ✓ | – | ✓ |
| `betreiber.verwalten` | ✓ | – | – |
| `protokoll.sehen`: Betreiberprotokoll | ✓ | – | – |

**Finanzen kommt nie in eine Praxis.** Wer Umsätze auswertet, braucht keinen
Blick in einen Posteingang.

## Die Anmeldung

**Ein eigener Eingang, derselbe Guard.** `/backoffice/anmelden` ist eine
eigene Seite mit eigenem Titel. Dahinter liegen weiterhin `users` und der
Guard `web`. Ein zweiter Guard würde die Impersonation aus WP-05 zerlegen,
die genau damit rechnet, dass der Betreiber ein `User` ist.

- `/backoffice/anmelden` nimmt nur Betreiberkonten an, `/login` nur
  Praxiskonten. **Die Abweisung kommt erst nach der Passwortprüfung.** Wer
  vorher abweist, verrät, welche Adressen Betreiberkonten sind.
- **Vorerst ohne zweiten Faktor** (C14). Als Ausgleich:
  - eine strengere Drosselung,
  - kein „Angemeldet bleiben",
  - eine Leerlauf-Abmeldung,
  - für jede wirksame Handlung das eigene Passwort im Dialog.
- Anmeldungen und Fehlversuche stehen im Protokoll, ohne Organisation. Ein
  Fehlversuch trägt die Kennung des Kontos, wenn es eines gibt, sonst nur
  die IP. **Nie die eingetippte Adresse** (C5): Ein Protokoll voller
  vertippter E-Mail-Adressen ist eine Adressliste.

## Schritte

1. **Zuerst der Fehler, Test zuerst.** WP-34 AK 8 („Eine gesperrte Praxis
   kommt nicht mehr hinein") ist nicht durchgesetzt. `suspended_at` prüft
   nur `ResolvePublicTenant`.
   - Neue Middleware `EnsurePraxisNichtGesperrt`, **zwischen `ResolveTenant`
     und `ApplyImpersonation`**, in der Web-Gruppe **und** in
     `prependToPriorityList` (`bootstrap/app.php`). Die zweite Kette in
     `tests/Feature/Tenancy/RoutenbindungTest.php` wird ergänzt.
   - Sie prüft `$user->organization->suspended_at`, das `ResolveTenant`
     schon geladen hat. Sie meldet ab und leitet mit Hinweis auf `login` um,
     nach dem Muster von `EnsureUserIsActive`.
   - Ebenso prüfen `App\Jobs\NachrichtEinordnen` und `App\Agent\Agentenlauf`
     die Sperre: Eingehendes wird gespeichert, aber nicht bearbeitet.
   - **Die Aufbewahrung läuft trotz Sperre weiter.** `mrs:aufbewahrung`
     überspringt heute gesperrte Praxen (`whereNull('suspended_at')`). Eine
     gesperrte Praxis behielte damit Daten über jede Frist hinaus, und
     genau das schließt Regel 3 aus („automatisch durchgesetzt, nicht auf
     Zuruf"). Die übrigen Läufe bleiben für gesperrte Praxen ausgesetzt.
2. `App\Enums\OperatorRole` und `App\Enums\OperatorAbility` als
   `string`-Enums. Die Zuordnung steht im Rollen-Enum, `label()` mit
   Umlauten.
3. **Migration:**
   - `users.operator_role` (`VARCHAR`, nullable) anlegen, aus
     `is_super_admin = 1` mit `super_admin` befüllen, danach
     `is_super_admin` entfernen.
   - Die Regel „Betreiber ⇒ `organization_id IS NULL AND role IS NULL`"
     setzt ein **BEFORE INSERT/UPDATE-Trigger** durch. Muster sind die
     Trigger an `audit_logs`, Migration `…000400`.
4. **`User`:** `istBetreiber()`, `betreiberRolle()`, `betreiberDarf()`.
   - **Alle sieben Aufrufer von `isSuperAdmin()`** einzeln umstellen, ohne
     Alias:
     - `User.php` (Fähigkeiten während der Impersonation)
     - `Impersonation::start()` (`support.zugriff`)
     - `EnsureSuperAdmin`
     - `HandleInertiaRequests`
     - `ApplyImpersonation`
     - `ImpersonationController`, zweimal
   - Die Spalte selbst steht außerdem in `User::casts()` und
     `auditableValues()`, in `UserFactory`, `DatabaseSeeder` und
     `BackofficeTest`.
   - `UserFactory` behält `superAdmin()` und bekommt `customerSuccess()` und
     `finanzen()`. Der Seeder legt je Rolle ein Konto an.
5. **`EnsureSuperAdmin` wird zu `EnsureBetreiber`**, Alias
   `betreiber[:fähigkeit]`.
   - Jede Route in `routes/backoffice.php` bekommt ihre Fähigkeit, **dazu
     `impersonation.store` und `impersonation.destroy` in
     `routes/settings.php`** (`support.zugriff`). Heute prüfen sie
     innen.
   - `backoffice/{organisation}` bekommt `->whereUuid('organisation')`.
     `auth.php` wird zuletzt geladen, sonst verschluckt die Route
     `anmelden`, `betreiber` und `protokoll`.
6. **Anmeldung:**
   - Controller und Seite `backoffice/Anmelden.vue` (`AuthLayout`).
   - `redirectGuestsTo` für `backoffice/*`.
   - Die Drosselung `mrs.backoffice.login_versuche` gilt je Adresse und IP.
   - Leerlauf-Abmeldung über eine Middleware in der Backoffice-Gruppe nach
     `mrs.backoffice.leerlauf_minuten`.
   - Kein Remember-Token für Betreiber.
   - Nach dem Anmelden auf `/dashboard`, das für Betreiber die Installation
     zeigt (WP-34, Nachtrag vom 27.09.2026).
   - Die Handlungen in `BackofficeController` verlangen `current_password`
     im Formular. **Nicht `password.confirm`**: Die Mittelschicht merkt sich
     bei POST die POST-Adresse als Ziel (405 nach der Bestätigung), und
     `auth.password_timeout` beträgt drei Stunden.
7. **Betreiberverwaltung** (`betreiber.verwalten`):
   - Seite `backoffice/Betreiber.vue`: anlegen mit Name, E-Mail und Rolle.
     `email_verified_at` wird gesetzt, denn die Routen verlangen
     `verified`, und der Passwortlink beweist die Adresse.
   - Das Passwort setzt die Person über `Password::sendResetLink`.
   - Rolle ändern, deaktivieren (`deactivated_at`).
   - **Den letzten aktiven Super-Admin kann niemand entfernen, sich selbst
     auch nicht.**
   - Für den ersten Super-Admin einer Installation:
     `mrs:betreiber {email} {--rolle=super_admin}`.
8. **Die Protokollflut abstellen.** `ApplyImpersonation` ruft heute bei
   jeder Anfrage eines Betreibers zweimal `acrossTenants()` auf (die
   laufende Sitzung, dann die Organisation). Das macht einen
   `tenant.cross_access`-Eintrag je Seitenaufruf, und die echten
   Querzugriffe gehen darin unter.
   - `ImpersonationController::store` legt neben der Sitzungskennung die
     Organisationskennung in der Session ab.
   - `ApplyImpersonation` liest die `Organization` direkt, denn sie ist
     kein TenantModel. Die Sitzung sucht es über `runAs($praxis, …)` mit
     `whereNull('ended_at')` und dem eigenen `impersonator_user_id`, also
     mandantengebunden statt über die Grenze.
   - Fehlt eines davon, werden beide Schlüssel verworfen.
   - Das Ereignis `Logout` beendet eine laufende Sitzung, sonst steht in
     der Praxis bis zum Ablauf „Support hat Zugriff".
   - Die Tests, die heute nur `impersonation_session_id` in die Session
     legen, bekommen einen Helfer: `ImpersonationTest`,
     `AnhangAusgabeTest`.
9. **Betreiberprotokoll** (`protokoll.sehen`):
   - Seite `backoffice/Protokoll.vue` mit den Einträgen ohne Organisation
     (Querzugriffe, Anmeldungen) und den Handlungen der Betreiber, gefiltert
     nach Ereignis, Betreiber und Zeitraum.
   - `App\Datenschutz\Aufbewahrung` bekommt einen Zweig für `audit_logs`
     mit `organization_id IS NULL`. Heute läuft sie nur je Mandant, und
     diese Zeilen bleiben ewig (C7: 36 Monate).
10. **Oberfläche:**
    - Die geteilte Eigenschaft `auth.superAdmin` wird zu `auth.betreiber:
      { rolle, faehigkeiten } | null`.
    - `AppSidebar.vue` zeigt die Punkte je Fähigkeit.
    - Nachzuziehen sind `types/index.ts`, `composables/useEinfuehrung.ts`
      (`BauteileTest` verlangt für jeden Link einen Eintrag) und
      `resources/js/ziggy.js`.
    - Neue Seiten müssen in `scripts/pruefe-build.mjs` bestehen.
11. Konfiguration `mrs.backoffice` mit `login_versuche` (3),
    `login_sperrminuten` (15) und `leerlauf_minuten` (30), jeweils mit
    Fundstelle **C14**.

## Abnahmekriterien

**Sperre**

1. Ein angemeldeter Benutzer einer gesperrten Praxis wird bei der nächsten
   Anfrage abgemeldet und sieht, warum.
2. Ein Betreiber, der diese Praxis impersoniert, bleibt drin.
3. Einer gesperrten Praxis antwortet der Agent nicht. Die eingehende
   Nachricht ist gespeichert.
4. Entsperren gibt den Zugang sofort wieder frei.
5. Die Aufbewahrung setzt ihre Fristen auch bei einer gesperrten Praxis
   durch.

**Rollen**

6. Jede Route unter `backoffice` und jede Impersonation-Route verlangt ihre
   Fähigkeit. Geprüft wird als Datensatz über Rolle × Route, mit 403 dort,
   wo die Tabelle oben ein Strich zeigt.
7. Customer Success kann nicht sperren, Finanzen kann keine Impersonation
   starten.
8. Ein Konto mit Betreiberrolle und `organization_id` lässt sich nicht
   speichern, auch nicht über `DB::table()`.
9. Eine Praxisinhaberin erreicht keine Backoffice-Route, auch nicht mit
   gesetzter Betreiberrolle einer anderen Person in der Session.
10. Die Migration macht aus jedem bisherigen Super-Admin einen Super-Admin.

**Anmeldung**

11. `/backoffice/anmelden` weist ein Praxiskonto ab, `/login` ein
    Betreiberkonto. Beide erst nach richtigem Passwort, mit derselben
    Meldung wie bei falschem Passwort.
12. Nach drei Fehlversuchen greift die Drosselung.
13. Nach der Leerlaufzeit ist der Betreiber abgemeldet.
14. Eine Sperre ohne richtiges `current_password` wirkt nicht.
15. Anmeldung und Fehlversuch stehen im Protokoll. Die eingetippte Adresse
    steht in keinem Eintrag.

**Verwaltung**

16. Ein Super-Admin legt ein CS-Konto an. Die Person bekommt den
    Passwortlink und kommt danach hinein.
17. Der letzte aktive Super-Admin lässt sich weder deaktivieren noch
    herabstufen, auch nicht von sich selbst.
18. Jede Änderung an einem Betreiberkonto steht mit Handelndem im Protokoll.

**Protokoll**

19. Ein Seitenaufruf während einer laufenden Impersonation schreibt keinen
    `tenant.cross_access`-Eintrag.
20. Das Abmelden beendet eine laufende Impersonation und protokolliert es.
21. Das Betreiberprotokoll zeigt Einträge ohne Organisation, CS bekommt 403.
22. Die Aufbewahrung löscht Einträge ohne Organisation nach 36 Monaten.

## Nicht in diesem Paket

- **Zweiter Faktor.** Zurückgestellt (C14). Die Anmeldeseite ist so
  gebaut, dass ein zweiter Schritt dazwischen passt.
- **Frei zusammenstellbare Rechte.** Wie in WP-04: feste Rollen.
- **Ein zweiter Guard oder eine eigene Tabelle für Betreiber.** Das würde
  die Impersonation zerlegen.
- **IP-Freigabelisten.** Gehören, wenn überhaupt, an den Rand der
  Infrastruktur.
- Support-PIN (WP-34b), Abo-Eingriffe (WP-34c), Finanzen (WP-34d).

## Fallstricke

- **Ein Alias für `isSuperAdmin()` versteckt die Frage, die jeder Aufrufer
  neu beantworten muss.** „Ist Betreiber" und „darf diese Handlung" sind
  zwei Fragen. Wer beide unter einem Namen weiterführt, gibt Finanzen den
  Support-Zugriff.
- **Ein CHECK geht nicht.** MySQL weist einen CHECK auf eine Spalte ab, die
  an einer Fremdschlüsselaktion hängt (Fehler 3823, `organization_id` hat
  `nullOnDelete`). Deshalb ein Trigger.
- **Die Sperre am `TenantContext` zu prüfen** wirft den Betreiber aus der
  Praxis, der er gerade helfen soll. Geprüft wird die eigene Organisation
  des Benutzers.
- **Eine neue Middleware außerhalb der Prioritätsliste** landet hinter
  `SubstituteBindings`. `RoutenbindungTest` fängt das nur, wenn die Kette
  ergänzt ist.
- **`password.confirm` auf POST-Routen** führt nach der Bestätigung auf einen
  405.
- **Die Route mit Platzhalter schluckt die festen.**
  `backoffice/{organisation}` passt auch auf `backoffice/anmelden`.
- **Ein Protokoll, in das jeder Seitenaufruf schreibt, ist keines.** Die
  Flut aus `ApplyImpersonation` ist kein Schönheitsfehler: Sie verdeckt
  genau die Querzugriffe, für die Regel 1 das Protokoll verlangt.

## Stand

Die 22 Abnahmekriterien laufen, in **45 Tests**:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Tenancy/SperreTest.php` (6) | 1–5 |
| `tests/Feature/Backoffice/BetreiberrollenTest.php` (10) | 6–9, dazu Geld nur mit `finanzen.sehen` und die geteilte Eigenschaft `auth.betreiber` |
| `tests/Parallel/BetreiberrollenMigrationTest.php` (1) | 10 |
| `tests/Feature/Backoffice/BetreiberAnmeldungTest.php` (11) | 11–15 |
| `tests/Feature/Backoffice/BetreiberverwaltungTest.php` (10) | 16–18, dazu `mrs:betreiber` |
| `tests/Feature/Backoffice/BetreiberprotokollTest.php` (7) | 19–22 |

Gesamtstand **1337 Tests, 5139 Zusicherungen**. `composer check` (Pint,
PHPStan Stufe 8, Pest) ist grün, ebenso `npm run format:check`, `npx eslint .`
und `npx vue-tsc --noEmit`. Der Build prüft 44 Seiten.

Neu:
- **Enums** `OperatorRole` und `OperatorAbility`, dazu `GuardrailHit::TenantSuspended`
  und sieben `AuditEvent`-Fälle `operator.*`.
- **Migration** `2026_09_27_120000_betreiberrollen` mit zwei Triggern.
- **Middleware** `EnsureBetreiber` (Alias `betreiber[:fähigkeit]`, ersetzt
  `EnsureSuperAdmin`), `EnsurePraxisNichtGesperrt` und `BetreiberLeerlauf`.
- **Anmeldung**: `BetreiberAnmeldungController` mit `BetreiberLoginRequest`.
- **Verwaltung**: `BetreiberController`, `App\Backoffice\Betreiberkonten` und
  `mrs:betreiber`.
- **Protokoll**: `BetreiberprotokollController` und der Listener
  `ImpersonationBeimAbmeldenBeenden`.
- **Seiten** `backoffice/{Anmelden,Betreiber,Protokoll}.vue`.
- **Konfiguration** `mrs.backoffice.{login_versuche, login_sperrminuten,
  leerlauf_minuten}`.
- **Seeder**: je Rolle ein Konto (`support@`, `cs@`, `finanzen@mrs-beauty.test`).

Im Browser nachgesehen:
- `/login` weist `support@mrs-beauty.test` mit „E-Mail-Adresse oder Passwort
  stimmen nicht" ab.
- `/backoffice/anmelden` lässt ihn hinein und führt auf das Dashboard der
  Installation.
- Die Seitenleiste zeigt Backoffice, Betreiberkonten und Betreiberprotokoll.
- Der Sperrdialog fragt nach dem eigenen Passwort.
- Die Migration hat den vorhandenen Seeder-Admin der Entwicklungsdatenbank
  zum Super-Admin gemacht.

## Was das Bauen zutage gefördert hat

**Die Sperre des Agenten gehört in den Not-Aus, nicht in zwei Aufträge.**
Das Briefing nannte `NachrichtEinordnen` und `Agentenlauf`. Beide laufen aber
durch `Schutz::notAus()` (G8), die eine Stelle, an der der Agent schon heute
je Mandant schweigt. Dort steht die Prüfung jetzt, mit eigenem Grund
`tenant_suspended`. Die Nachricht ist gespeichert, der Lauf steht als
übersprungen im Protokoll, und das Modell wird nicht gefragt.

**`actingAs()` reicht dasselbe Benutzerobjekt weiter.** Der erste Test zur
Sperre blieb grün, wo er rot sein sollte, und rot, wo er grün sein sollte: Die
Organisation hing aus der ersten Anfrage noch am Objekt, samt
`suspended_at = null`. Eine echte Anfrage lädt den Benutzer jedes Mal neu aus
der Sitzung. Der Test tut das jetzt auch, statt der Middleware eine
zusätzliche Abfrage je Anfrage aufzubürden.

**Erst prüfen, dann anmelden.** `/login` meldete mit `Auth::attempt()` an und
hätte einen Betreiber danach wieder abmelden müssen. Das Abmelden löst
`Logout` aus, und der neue Listener hätte bei jedem abgewiesenen Versuch nach
einer laufenden Impersonation gesucht, also einen Querzugriff geschrieben.
Beide Anmeldungen prüfen jetzt mit `Auth::validate()` und melden erst an,
wenn feststeht, dass die Person hier hinein darf.

**Die Impersonation hängt jetzt an der Browsersitzung.** Das war die Folge
der Lösung gegen die Protokollflut, und sie ist gewollt. Bisher galt eine
laufende Sitzung für jeden Browser desselben Betreibers, weil die Middleware
sie über `impersonator_user_id` quer suchte. Jetzt legt der Start
Sitzungskennung und Praxis in die Session. Die Middleware sucht mandantengebunden
und nur die eigene Sitzung. Ein zweiter Betreiber, dem jemand die Kennung
unterschöbe, sieht nichts (Test „nur mit der eigenen Sitzung").

**Beim Abmelden bleibt ein Querzugriff.** Der Listener fragt
`laufendeVon()`, und das liest quer, einmal je Abmeldung eines Betreibers.
Über die Session allein fände er nur die Sitzung dieses Browsers, und eine in
einem anderen Browser liefe weiter. Die Abwägung ist bewusst so getroffen.

**Wer sein Passwort setzt, landete an `/login`.** Ein neues Betreiberkonto
bekommt den gewöhnlichen Link zum Zurücksetzen, und der führte nach dem
Speichern auf die Anmeldung der Praxen, wo das Konto jetzt abgewiesen wird.
`NewPasswordController` und das Abmelden führen Betreiber deshalb an ihren
eigenen Eingang.

**Die Aufbewahrung ließ zwei Dinge aus.** Sie übersprang gesperrte Praxen
(Regel 3), und das Protokoll ohne Organisation hatte gar keine Frist. Beides
läuft jetzt in `mrs:aufbewahrung` mit. Die Frist für Einträge ohne
Organisation ist die aus C7 und nicht die einer Praxis, denn keine Praxis kann
sie für den Betreiber verkürzen.

**Die geprüfte Migration braucht eine eigene Datenbank.** AK 10 rollt die
Migration zurück und wieder vor. MySQL schließt dabei jede offene
Transaktion, also auch die von `RefreshDatabase`. Der Test steht deshalb in
`tests/Parallel`, wie die Nebenläufigkeitstests, mit eigenem
`migrate:fresh`.

**Zwei Kleinigkeiten am Rand.** `resources/js/ziggy.js` war veraltet (86 von
187 Routen) und ist neu erzeugt. In der Seitenleiste leuchteten auf
`/backoffice/betreiber` zwei Punkte. `NavMain` lässt jetzt den genauesten
gewinnen.

**Am selben Tag verschoben, auf Wunsch:** Die Kennzahlen der Installation
stehen auf `/dashboard` (Seite `DashboardBetreiber`), das Backoffice zeigt
nur noch die Liste der Praxen. Umsatz und Modellkosten fehlen dort für jede
Rolle ohne `finanzen.sehen`, in der Antwort selbst und nicht nur in der
Anzeige.

## Offen

- **Zweiter Faktor** (C14, zurückgestellt).
- **Den Rollenkatalog bestätigen.** Die Tabelle oben ist abgeleitet.
- **Die Mail zum Passwort** sagt auch einem neuen Konto „weil für Ihren
  Zugang ein neues Passwort angefordert wurde". Richtig ist: ein erstes.
  Eine eigene Willkommensmail wäre die saubere Lösung.
- **`Mandantenuebersicht::liste()`** stellt weiterhin vier Abfragen je
  Praxis. Umgestellt wird das mit den Filtern in WP-34c.
