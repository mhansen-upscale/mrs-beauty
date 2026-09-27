# WP-35 · Zweiter Faktor

> Nachtrag zu WP-04 und WP-34a. Bis hier genügt ein Passwort. Wer es kennt,
> liest die Posteingänge einer ästhetischen Praxis — und bei einem
> Betreiberkonto die Zustände aller Praxen. C14 hat den zweiten Faktor für
> Betreiber zurückgestellt; C16 holt ihn für alle nach, **freiwillig**.

## Ziel
Jede Person kann ihre Anmeldung mit einem zweiten Faktor absichern — per
Authenticator-App oder per Code an die eigene E-Mail-Adresse. Niemand muss,
wer es nicht hat, sieht einen Hinweis.

## Vorher lesen
- `docs/entscheidungen.md`: **C16** (dieses Paket), **C14**, **C5**, **C15**
  (dasselbe Muster für einen kurzen Code), **A5**, **A7**, **A11**
- `CLAUDE.md`, **Regel 1** und **Regel 3**
- `specs/WP-34a-betreiberrollen-anmeldung.md`, Abschnitt „Die Anmeldung" und
  die Fallstricke (`password.confirm`, Routenreihenfolge)
- `specs/WP-04-benutzer-rollen-einladungen.md`, Deaktivierung
- `specs/WP-05-audit-log-impersonation.md`, AK 7 (Wertsuche)

## Voraussetzungen
WP-04, WP-05, WP-34a.

## Die Linie, an der alles hängt

**Nach dem Passwort ist noch niemand angemeldet.** Es gibt keine halbe
Sitzung, die eine Middleware erst wieder einsperren müsste. Das Passwort
eröffnet eine *ausstehende Anmeldung* in der Sitzung; angemeldet wird erst,
wenn der zweite Faktor stimmt. Wer ihn nicht eingerichtet hat, merkt davon
nichts.

Deshalb:

- **Beide Eingänge prüfen weiter zuerst, was sie heute prüfen**, und erst
  danach kommt der Code. Ein Betreiber an `/login` und ein Praxiskonto an
  `/backoffice/anmelden` bekommen dieselbe Meldung wie bei falschem
  Passwort — **keinen Code-Schritt, keine Mail**. Käme der Code-Schritt
  vorher, verriete er, welche Adressen Betreiberkonten sind. Genau das hat
  WP-34a verhindert.
- **Eine deaktivierte Person und eine gesperrte Praxis kommen nicht bis zum
  Code.** Sonst ginge eine Mail an jemanden, der gar nicht hinein darf.
- **Ein Geheimnis zählt erst, wenn es bestätigt ist.** Bis dahin liegt es in
  der Sitzung, nicht in der Datenbank.
- **Freiwillig heißt nicht beiläufig.** Wer keinen zweiten Faktor hat, sieht
  im Produkt einen Hinweis — keinen, der die Arbeit versperrt.

**Ein Code mit sechs Stellen ist schwach, und das ist in Ordnung**, solange
drei Dinge gelten: Er lebt kurz, die ausstehende Anmeldung verbrennt nach
fünf Fehlversuchen, und je Person wird über alle Sitzungen gedrosselt. Fehlt
eines davon, ist er ratbar (wie C15).

## Zwei Verfahren, eines je Person

| | Authenticator-App | E-Mail-Code |
|---|---|---|
| Faktor | TOTP nach RFC 6238, 6 Stellen, 30 Sekunden | 6 Stellen an die Kontoadresse |
| Einrichtung | QR-Code und Schlüssel, bestätigt durch einen Code | Code an die eigene Adresse, bestätigt durch ihn |
| Verloren | 8 Wiederherstellungscodes | — (das Postfach ist der Faktor) |
| Empfohlen | ja | für alle ohne App |

**Der E-Mail-Code schützt vor einem geleakten Passwort, nicht vor einem
übernommenen Postfach** — über dasselbe Postfach läuft das Zurücksetzen des
Passworts. Deshalb empfiehlt das Produkt die App und sagt warum.

## Schritte

1. **Spalten an `users`** (Migration `…_zwei_faktor_am_benutzer`):
   - `zwei_faktor_verfahren` VARCHAR(16) null, Enum `ZweiFaktorVerfahren`
     (`authenticator`, `email`) — kein MySQL-ENUM (A11).
   - `zwei_faktor_geheimnis` TEXT null, Cast **`encrypted`** (APP_KEY).
     **Nicht** `App\Casts\Encrypted`: Betreiber haben keine Organisation, und
     das Crypto-Löschen einer Praxis (A5) sperrte die Person aus.
   - `zwei_faktor_wiederherstellung` JSON null — nur SHA-256-Hashes.
   - `zwei_faktor_bestaetigt_at` DATETIME null (A7).
   - `zwei_faktor_letzter_schritt` BIGINT UNSIGNED null — gegen Replay.
   - `zwei_faktor_hinweis_ausgeblendet_at` DATETIME null.
   - Geheimnis, Codes und letzter Schritt in **`User::$hidden`**:
     `HandleInertiaRequests` teilt `auth.user` vollständig.
   - `zwei_faktor_verfahren` in `auditableValues()`.
   - Lesende Methoden über `getAttributes()` wie `betreiberRolle()`.
   - `UserFactory`: neue Spalten null, States `mitAuthenticator()` und
     `mitEmailCode()`.
2. **`config/mrs.php`, Gruppe `zwei_faktor`** mit Fundstelle C16.
3. **Dienste unter `app/ZweiFaktor/`**:
   - `Authenticator` kapselt `pragmarx/google2fa`. **Die Zeit wird immer
     übergeben**, aus `CarbonImmutable::now()` — google2fa liest sonst die
     Systemuhr und ignoriert die Testzeit. Replay-Schutz als atomares
     `UPDATE … WHERE zwei_faktor_letzter_schritt IS NULL OR … < ?`: nur wenn
     genau eine Zeile betroffen ist, gilt der Code.
   - `Wiederherstellungscodes`: 8 × 16 Zeichen (`ABCD-EFGH-JKMN-PQRS`, 80
     Bit), SHA-256 wie `Invitation::hashe()`. Einlösen in einer Transaktion
     mit `lockForUpdate`. Groß-/Kleinschreibung, Bindestriche und
     Leerzeichen zählen nicht.
   - `EmailCode`: `random_int`, im Cache als `Hash::make`, mit
     Fehlversuchszähler und Laufzeit. Schlüssel
     `zwei-faktor:code:{anmeldung|einrichtung}:{uuid}` — ein Einrichtungscode
     wirkt nicht bei der Anmeldung. Ein neuer Code ersetzt den alten.
     Erneutes Senden mit Abstand und Stundenhöchstzahl.
   - `ZweiterFaktor`: einschalten, wechseln, abschalten, zurücksetzen,
     jeweils mit Protokoll und neuem `remember_token`.
   - `AusstehendeAnmeldung`: `beginne()` und `abschliessen()`, siehe 4.
4. **Die Anmeldung.**
   - `LoginRequest::authenticate()` und `BetreiberLoginRequest::authenticate()`
     geben den geprüften `User` zurück und melden **nicht mehr selbst** an.
     Drosselung, Meldungen und Protokoll der Fehlversuche bleiben.
   - `/login`: Passwort → Betreiber abweisen → gesperrte Praxis →
     **deaktiviert** → zweiter Faktor.
   - `/backoffice/anmelden`: Passwort und Betreiber → **deaktiviert** →
     zweiter Faktor.
   - Ohne zweiten Faktor ruft der Controller sofort `abschliessen()`.
   - `beginne()` legt in die Sitzung: `uuid`, Eingang (`praxis` oder
     `betreiber`), Merken (beim Betreiber immer `false`), Ablauf,
     Fehlversuche, einen Fingerabdruck des Passwort-Hashes. Beim E-Mail-
     Verfahren geht der Code los.
   - `abschliessen()`: `Auth::login`, Sitzung erneuern, `intended`. Für den
     Betreiber außerdem, was bisher Request und Controller taten:
     `OperatorLoggedIn` ins Protokoll, `BetreiberLeerlauf::SESSION_KEY`
     setzen — **dort und nur dort**.
5. **Die Code-Seite.** `Auth/ZweiFaktorAnmeldungController`, eine Seite
   `auth/ZweiFaktor.vue` im `AuthLayout`, zwei Gast-Routen:
   `login/zwei-faktor` und `backoffice/anmelden/code` (vor
   `backoffice/{organisation}`). Jede nimmt nur ausstehende Anmeldungen
   ihres Eingangs an.
   - Vor jeder Prüfung erneut: Ablauf, Fingerabdruck, zweiter Faktor noch
     aktiv, Konto noch aktiv, Praxis nicht gesperrt.
   - Nach `max_versuche` ist die Anmeldung verworfen
     (`two_factor.challenge_locked`).
   - Beim Betreiber steht jeder falsche Code als `OperatorLoginFailed` im
     Protokoll, Kontext `schritt: code` — wie jeder Fehlversuch in WP-34a.
   - Ein Eingabefeld mit `autocomplete="one-time-code"` und
     `inputmode="numeric"`, bei der App der Wechsel zum
     Wiederherstellungscode, beim E-Mail-Code „Erneut senden".
6. **Die Mail.** `Notifications\Anmeldecode`, nur Mail, **sofort, ohne
   `ShouldQueue`**: Die Person wartet, und in der Warteschlange stünde der
   Code im Klartext — in Redis und in `failed_jobs`. Produktlayout, der Code
   nicht im Betreff, „Nicht Sie? Dann ändern Sie Ihr Passwort." Ein
   Versandfehler wird zum Hinweis auf der Seite, nicht zu einem 500.
7. **Einstellungen → Zweiter Faktor** (`settings/zwei-faktor`, ohne `can:`,
   auch für Betreiber):
   - Einrichten, wechseln, abschalten und neue Codes nur mit
     `current_password` — **nicht `password.confirm`** (405 nach einem POST).
   - Das Geheimnis der App bleibt bis zur Bestätigung in der Sitzung. QR-Code
     über `App\Support\QrCode::svg()` als `<img>`-Data-URL, nie `v-html`.
   - Die Wiederherstellungscodes erscheinen einmal. Danach ein `replace`-
     Besuch, damit sie nicht in der Browser-History stehen.
   - Beim Wechsel bleibt das alte Verfahren aktiv, bis das neue bestätigt ist.
   - Während einer Impersonation nichts davon (403).
   - Solange das E-Mail-Verfahren aktiv ist, ändert das Profil die Adresse
     nicht — erst wechseln oder abschalten.
8. **Der Hinweis.** `auth.zweiFaktor: { aktiv, verfahren, hinweis }`,
   Komponente `ZweiFaktorHinweis` im `AppLayout`. „Später" setzt
   `zwei_faktor_hinweis_ausgeblendet_at`, nach `hinweis_pause_tage` kommt er
   wieder. Nicht während der Einführung, nicht in einer Impersonation, nicht
   auf der Seite des zweiten Faktors selbst.
9. **Zurücksetzen, wenn das Handy weg ist.**
   - Teamseite (`can:team.manage`): Spalte „Zweiter Faktor", Aktion mit
     eigenem Passwort. Nur die eigene Praxis, nie sich selbst, nie in einer
     Impersonation. Den Faktor einer Inhaberin setzt nur eine Inhaberin
     zurück (*abgeleitet, zu bestätigen*).
   - Betreiberverwaltung (`betreiber:betreiber.verwalten`): dasselbe für
     andere Betreiber.
   - `mrs:zwei-faktor-zuruecksetzen {email} {--grund=}` für die letzte
     Inhaberin und den letzten Super-Admin, mit Rückfrage, der Grund im
     Protokoll.
10. **Protokoll** (C5): `two_factor.enabled`, `.disabled`, `.reset`,
    `.recovery_codes_renewed`, `.recovery_code_used`, `.challenge_locked`.
    Im Kontext nur Verfahren oder verbleibende Anzahl. Praxisfälle mit
    `organizationId`, Betreiber mit `ohneOrganisation: true`.
11. **`bootstrap/app.php`:** die Code-Felder in `dontFlash`.
12. **Doku:** `docs/datenmodell.md` (Spalten, APP_KEY statt Praxisschlüssel),
    `docs/betrieb.md` (Runbook „Zweiter Faktor verloren").

## Abnahmekriterien

**Einrichtung**

1. Die App wird erst nach dem Passwort angeboten, mit QR-Code und Schlüssel,
   und ist erst nach einem gültigen Code eingeschaltet.
2. Ohne `current_password` gibt es keine Einrichtung, kein Abschalten und
   keine neuen Codes.
3. Bis zur Bestätigung liegt das Geheimnis nur in der Sitzung. Ein falscher
   Code schaltet nichts ein.
4. Die acht Wiederherstellungscodes erscheinen genau einmal. In der Datenbank
   stehen nur Hashes.
5. Neue Codes entwerten alle alten.
6. Das E-Mail-Verfahren ist erst nach dem Code an die eigene Adresse
   eingeschaltet.
7. Beim Wechsel bleibt das alte Verfahren aktiv, bis das neue bestätigt ist.
   Danach sind Geheimnis und Codes des alten gelöscht.
8. Abschalten löscht Verfahren, Geheimnis und Codes.
9. Einschalten, Wechsel und Zurücksetzen erneuern das `remember_token`.
10. Geheimnis, Codes und letzter Schritt stehen in keiner Inertia-Antwort.
    Das Geheimnis steht in der Datenbank nie im Klartext.
11. Ein Betreiber ohne Organisation richtet den zweiten Faktor genauso ein.
12. Während einer Impersonation lässt er sich nicht ändern.
13. Solange das E-Mail-Verfahren aktiv ist, lässt sich die Adresse nicht
    ändern.

**Anmeldung an `/login`**

14. Ohne zweiten Faktor bleibt die Anmeldung unverändert.
15. Mit zweitem Faktor ist nach dem Passwort niemand angemeldet. Erst der
    Code meldet an, erneuert die Sitzung und führt zum ursprünglichen Ziel.
16. „Angemeldet bleiben" wirkt erst nach dem Code.
17. Ein Code aus dem Takt davor oder danach gilt, einer zwei Takte daneben
    nicht.
18. Derselbe App-Code wirkt genau einmal, auch bei zwei gleichzeitigen
    Anmeldungen.
19. Nach fünf falschen Codes ist die ausstehende Anmeldung verworfen. Auch
    der richtige Code wirkt dann nicht mehr.
20. Die ausstehende Anmeldung verfällt nach `anmeldung_gueltig_minuten`.
21. Je Person wird über alle Sitzungen gedrosselt.
22. Ohne ausstehende Anmeldung führt die Code-Seite zu ihrem Eingang.
23. Eine deaktivierte Person und eine Person aus einer gesperrten Praxis
    bekommen nach richtigem Passwort weder den Code-Schritt noch eine Mail.
24. Nach einer Passwortänderung oder einem Zurücksetzen gilt die ausstehende
    Anmeldung nicht mehr.

**Anmeldung an `/backoffice/anmelden`**

25. Ein Betreiber mit zweitem Faktor ist erst nach dem Code angemeldet, nie
    dauerhaft. Die Leerlauffrist beginnt mit dem Code.
26. `OperatorLoggedIn` steht erst nach dem Code im Protokoll. Jeder falsche
    Code steht dort als `OperatorLoginFailed`, ohne Organisation.
27. Ein Betreiber mit zweitem Faktor an `/login` und ein Praxiskonto mit
    zweitem Faktor an `/backoffice/anmelden` bekommen `auth.failed` —
    **keinen Code-Schritt und keine Mail**.
28. Eine ausstehende Anmeldung des einen Eingangs gilt an der Code-Route des
    anderen nicht.

**E-Mail-Code**

29. Nach dem Passwort geht genau eine Mail mit sechsstelligem Code. Er steht
    nicht im Betreff.
30. Er gilt `email_code_gueltig_minuten` und einmal. Ein neuer entwertet den
    alten.
31. „Erneut senden" folgt Abstand und Stundenhöchstzahl.
32. Ein Einrichtungscode wirkt nicht bei der Anmeldung und umgekehrt.
33. Der Code steht nie im Klartext in Cache, Sitzung, Log, Protokoll oder
    Warteschlange.

**Wiederherstellung**

34. Mit einem Wiederherstellungscode kommt man ohne App hinein.
    Schreibweise, Bindestriche und Leerzeichen sind egal.
35. Jeder wirkt einmal. Die Person sieht, wie viele übrig sind, und bei zwei
    oder weniger den Rat, neue zu erzeugen.

**Zurücksetzen**

36. Wer `team.manage` hat, setzt den zweiten Faktor eines Mitglieds der
    eigenen Praxis mit dem eigenen Passwort zurück. Eine fremde Praxis
    bekommt 404.
37. Den zweiten Faktor einer Inhaberin setzt nur eine Inhaberin zurück.
    Niemand setzt den eigenen über die Teamseite zurück, der Support in der
    Impersonation gar keinen.
38. Wer `betreiber.verwalten` hat, setzt den zweiten Faktor eines anderen
    Betreibers zurück. Ohne die Fähigkeit 403.
39. `mrs:zwei-faktor-zuruecksetzen` wirkt für Praxis und Betreiber, verlangt
    eine Begründung und protokolliert sie.
40. Teamseite und Betreiberverwaltung zeigen je Person das Verfahren oder
    „aus".

**Hinweis**

41. Ohne zweiten Faktor erscheint im `AppLayout` ein Hinweis mit Link zu den
    Einstellungen, für Praxis und Betreiber. Mit zweitem Faktor nicht.
42. Ausgeblendet bleibt er `hinweis_pause_tage` weg, auf jedem Gerät.
43. Er erscheint nicht während der Einführung, nicht in einer Impersonation
    und nicht auf der Buchungsseite.

**Protokoll**

44. Ein-, Ab- und Umschalten, neue Codes, eingelöste Wiederherstellungscodes,
    Zurücksetzen (mit Handelndem) und verworfene Anmeldungen stehen im
    Protokoll — bei Praxispersonen in dem der Praxis, bei Betreibern ohne
    Organisation.
45. Kein Eintrag, keine Logzeile und keine Antwort enthält Geheimnis, Code
    oder Wiederherstellungscode. Geprüft wird durch Suche nach dem Wert, wie
    in WP-05 AK 7.

**Deutsch**

46. Die Mail und alle Meldungen des Pakets sind deutsch, mit Umlauten, die
    Mail im Produktlayout und nicht im Layout einer Praxis.

## Nicht in diesem Paket

- **Pflicht.** Weder je Praxis noch für Betreiber (C16). Wer sie will,
  ändert zuerst die Entscheidung.
- **Überall abmelden.** Andere laufende Sitzungen beenden braucht
  `AuthenticateSession` und `logoutOtherDevices()` und ändert jede Anfrage.
  Das ist ein eigenes Paket und schließt dieselbe Lücke beim
  Passwortwechsel. Bis dahin enden fremde Sitzungen mit ihrer Laufzeit.
- **Passkeys, SMS, „Dieses Gerät 30 Tage merken".**

## Fallstricke

- **`auth.user` gibt das Geheimnis an den Browser**, wenn `$hidden` nicht
  mitwächst.
- **Die Betreiber-Nebenwirkungen doppelt.** `OperatorLoggedIn` und der
  Leerlauf-Zeitstempel ziehen nach `abschliessen()` um. Bleiben sie auch im
  Request oder Controller stehen, steht jede Anmeldung zweimal im Protokoll —
  und die erste, bevor der Code stimmte.
- **Alte Remember-Cookies.** Ohne neues `remember_token` beim Einschalten
  umgeht ein Cookie von vorgestern den Code.
- **google2fa und die Testzeit.** Ohne übergebene Zeit scheitern die Tests
  zufällig an der Taktgrenze.
- **Doppelt eingelöst.** Zwei gleichzeitige Anmeldungen mit demselben Code
  kommen beide durch, wenn der letzte Schritt gelesen und danach geschrieben
  wird statt in einem `UPDATE`.
- **Validierungsfehler kopieren die Eingabe in die Sitzung.** Die Code-Felder
  gehören in `dontFlash`, neben die Passwörter.
- **`backoffice/{organisation}` verschluckt `backoffice/anmelden/code`**,
  wenn die Route dahinter steht.
- **`encryptHistory` braucht HTTPS**, lokal läuft HTTP. Deshalb der
  `replace`-Besuch statt verschlüsselter History.
