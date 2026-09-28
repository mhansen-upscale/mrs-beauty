# WP-34b · Freigabe per Einmal-PIN

> Nachtrag zu WP-05 und WP-34. Der Vollzugriff braucht die Freigabe einer
> Inhaberin (C4). Heute heißt das: Sie ist angemeldet, während der Support
> in ihrer Praxis steht, und klickt in *Organisation → Team* auf
> „Vollzugriff freigeben". Am Telefon ist das umständlich. Die Inhaberin sitzt
> am Empfang, der Support wartet, und keiner von beiden sieht, was der
> andere sieht.

## Ziel
Die Inhaberin erzeugt eine Einmal-PIN und nennt sie dem Support. Der
Support gibt sie ein und darf befristet Daten der Praxis anpassen, ohne
dass die Freigabe dadurch weniger ihre wäre.

## Vorher lesen
- `docs/entscheidungen.md`: **C15** (dieses Paket), **C4**, **C5**
- `CLAUDE.md`, **Regel 1** und **Regel 3**
- `specs/WP-05-audit-log-impersonation.md`, vollständig. Besonders
  „Wer sich selbst freigeben könnte, hätte Entscheidung C4 ausgehebelt".
- `specs/WP-34a-betreiberrollen-anmeldung.md`, Fähigkeit `support.zugriff`

## Voraussetzungen
WP-05, WP-34a.

## Die Linie, an der alles hängt

**Die Praxis gibt frei, nicht der Betreiber. Die PIN ist ein zweiter Weg zu
derselben Freigabe, kein Generalschlüssel.**

Deshalb:

- **Nur wer heute per Klick freigeben darf, erzeugt eine PIN.** Das ist
  `Ability::ApproveImpersonation`, also die Inhaberin. Ein Betreiber in der
  Impersonation hat diese Fähigkeit ausdrücklich nicht (`User.php`, WP-05)
  und kann sich deshalb keine eigene PIN erzeugen. Das ist der Kern des
  Pakets und bekommt einen eigenen Test.
- **Die PIN wirkt nur einmal, nur kurz und nur für diese Praxis.** Sie ist
  der Ersatz für einen Klick, nicht für eine Vollmacht.
- **Der Vollzugriff danach ist derselbe wie nach dem Klick:** befristet
  (`mrs.impersonation.full_ttl_minutes`), protokolliert, in jeder Antwort
  erkennbar. `approved_by_user_id` nennt die Inhaberin, die die PIN erzeugt
  hat, nicht den Betreiber.
- **Die Praxis sieht, dass jemand drin ist.** Solange Vollzugriff läuft,
  zeigt das Produkt es allen Benutzern der Praxis. Die Inhaberin kann ihn
  mit einem Klick beenden. „Sichtbar für beide Seiten" (WP-34) heißt genau
  das.

**Eine PIN mit sechs Stellen ist schwach, und das ist in Ordnung**, solange
drei Dinge gelten: Sie lebt 15 Minuten, sie verbrennt nach fünf
Fehlversuchen, und ein Betreiber kann nicht über viele Praxen hinweg
durchprobieren. Fehlt eines davon, ist sie ratbar.

## Schritte

1. **Tabelle `support_pins`** (`TenantSchema::base()`, Modell `SupportPin`
   als TenantModel):
   - Spalten: `created_by_user_id`, `pin_hash`, `expires_at`, `used_at`,
     `used_by_user_id`, `impersonation_session_id`, `failed_attempts`,
     `revoked_at`.
   - `open_guard` als **VIRTUAL** erzeugte Spalte mit UNIQUE: je Praxis
     höchstens eine offene PIN. Das Muster und die Begründung für VIRTUAL
     stehen am `running_guard` von `impersonation_sessions`.
   - Alle Zeitspalten sind `DATETIME` (`ZeitspaltenTest`).
2. **`App\Audit\Supportfreigabe`**:
   - `erzeuge(User $inhaberin): string` verlangt
     `Ability::ApproveImpersonation`.
     - In **einer** Transaktion: offene, aber abgelaufene PINs widerrufen
       (der Guard kennt `expires_at` nicht), dann die neue anlegen.
     - Die PIN kommt aus `random_int`, wird mit Nullen aufgefüllt und als
       `Hash::make` gespeichert. Der Klartext wird genau einmal
       zurückgegeben.
   - `widerrufe(User $inhaberin): void`.
   - `loese(ImpersonationSession $sitzung, User $betreiber, string $pin):
     ImpersonationSession` läuft in `runAs($praxis)`.
     - Voraussetzungen: eine laufende Sitzung **dieses** Betreibers in
       **dieser** Praxis, `support.zugriff`, eine offene PIN, nicht
       abgelaufen, `Hash::check`.
     - Ein Fehlversuch zählt `failed_attempts` hoch. Beim
       `mrs.support_pin.max_versuche`-ten verbrennt die PIN.
     - Zusätzlich gibt es einen `RateLimiter` je Betreiber über **alle**
       Praxen (`versuche_je_betreiber_stunde`).
     - Bei Erfolg: `used_at`, `used_by_user_id`,
       `impersonation_session_id`, dann die Freigabe.
3. **`Impersonation::approve()`** bekommt den Freigabeweg (`klick` | `pin`)
   und die freigebende Inhaberin. Neue Spalte
   `impersonation_sessions.approval_method`. Der Klickweg bleibt Wort für
   Wort, was er ist.
4. **Beenden durch die Praxis:** eine neue Route
   `impersonation/{session}/beenden` mit `can:impersonation.approve` für
   die Inhaberin. `Impersonation::end($sitzung, 'ended_by_tenant')`.
5. **Konfiguration `mrs.support_pin`**, jeweils mit Fundstelle **C15**:
   `laenge` (6), `gueltig_minuten` (15), `max_versuche` (5),
   `versuche_je_betreiber_stunde` (10).
6. **Die Seite der Praxis** (`organisation/Team.vue`, wo die Klick-Freigabe
   schon sitzt, Abschnitt „Support-Zugriff"):
   - „Einmal-PIN erzeugen". Die PIN erscheint groß, einmal, mit
     Countdown. Beim Neuladen ist sie weg.
   - „PIN widerrufen", solange sie offen ist.
   - Die Liste der Support-Sitzungen: wann, welcher Betreiber (Name),
     Begründung, Modus, Freigabeweg, Ende.
   - „Zugriff beenden" bei laufender Sitzung.
7. **Der Hinweis in der Praxis:** Solange eine Sitzung im Modus `full`
   läuft, zeigt `AppLayout` allen Benutzern der Praxis einen Hinweis („Der
   Support hat bis 14:30 Vollzugriff"). Die Inhaberin sieht darin den Knopf
   zum Beenden. Er kommt über eine geteilte Eigenschaft, gerechnet im
   Mandanten, **nicht** über `acrossTenants()`.
8. **Die Seite des Betreibers** (`support.zugriff`):
   - Im Mandantenblatt bekommt der Dialog „In die Praxis sehen" ein
     optionales Feld „PIN der Praxis". Ohne PIN startet die Sitzung
     maskiert wie heute. Mit PIN heißt Start plus Freigabe in einem Schritt.
   - Im `ImpersonationBanner` bei maskierter Sitzung: ein Feld „PIN
     eingeben".
9. **`AuditEvent`**: `support_pin.created`, `support_pin.revoked`,
   `support_pin.failed`, `support_pin.burned`, `support_pin.redeemed`,
   `impersonation.ended_by_tenant`. Die Freigabe selbst bleibt
   `impersonation.approved`, im Kontext steht `via`.

## Abnahmekriterien

**Erzeugen**

1. Eine Inhaberin erzeugt eine PIN und sieht sie genau einmal.
2. Eine Person ohne `impersonation.approve` erzeugt keine PIN.
3. **Ein Betreiber, der die Praxis impersoniert, erzeugt keine PIN**, auch
   nicht im Modus `full`.
4. Eine neue PIN macht die vorige ungültig.
5. Eine abgelaufene, noch offene PIN verhindert keine neue.

**Einlösen**

6. Mit der richtigen PIN wird die laufende Sitzung zu `full`. Sie nennt die
   Inhaberin als Freigebende und `pin` als Weg.
7. Die PIN von Praxis A wirkt nicht in einer Sitzung bei Praxis B.
8. Eine abgelaufene, verbrauchte oder widerrufene PIN wirkt nicht.
9. Der fünfte Fehlversuch verbrennt die PIN. Auch die richtige wirkt danach
   nicht mehr.
10. Ein Betreiber, der über mehrere Praxen hinweg rät, wird nach der
    konfigurierten Zahl gedrosselt.
11. Die Rolle Finanzen kann keine PIN einlösen.
12. Der Vollzugriff endet nach `full_ttl_minutes`, auch ohne Aufräumjob.

**Sichtbarkeit**

13. Solange Vollzugriff läuft, sehen alle Benutzer der Praxis den Hinweis.
14. Die Inhaberin beendet den Zugriff. Die nächste Anfrage des Betreibers
    läuft ohne Impersonation.
15. Erzeugen, Widerrufen, Fehlversuch, Verbrennen, Einlösen und Beenden
    stehen im Protokoll der Praxis.
16. **Die Klartext-PIN steht in keinem Protokolleintrag, keiner Logzeile und
    keiner Antwort** außer der einen an die Inhaberin. Geprüft wird durch
    Suche nach dem Wert, wie in WP-05 AK 7.

**Bestand**

17. Die Klick-Freigabe funktioniert unverändert, und alle Tests aus
    `tests/Feature/Audit/ImpersonationTest.php` bleiben grün.

## Nicht in diesem Paket

- **Eine dauerhafte Support-PIN.** Bewusst nicht (C15): Eine einmal
  genannte PIN, die weiterwirkt, ist ein Passwort, das jemand anderes kennt.
- **Umfang je Freigabe** (etwa „nur Stammdaten"). Der Vollzugriff ist, was
  WP-05 festlegt. Ein feinerer Zuschnitt wäre ein eigenes Paket.
- **Benachrichtigung per E-Mail oder WhatsApp** bei Einlösung. Der Hinweis im
  Produkt und das Protokoll reichen vorerst.
- **Zweiter Faktor für Betreiber.** Freiwillig, mit WP-35 (C16).

## Fallstricke

- **Die PIN im Protokoll.** Ein `kontext`, der „der Vollständigkeit halber"
  die eingegebene PIN mitschreibt, macht aus dem Protokoll eine
  Schlüsselliste (C5). Auch keine Fehlermeldung darf sie wiederholen.
- **Der Betreiber, der sich selbst freigibt.** Wer die PIN-Erzeugung an eine
  Rolle statt an `ApproveImpersonation` hängt, übersieht, dass der
  Betreiber während der Impersonation die Rolle einer Inhaberin spielt.
- **Ein UNIQUE-Guard ohne Zeitbezug.** `open_guard` weiß nicht, dass eine PIN
  abgelaufen ist. Ohne das Widerrufen in derselben Transaktion bekommt die
  Inhaberin nach 15 Minuten keine neue PIN.
- **`Hash::check` ist nicht das Einzige, was zeitkonstant sein muss.** Die
  Antwort auf „keine offene PIN" und „falsche PIN" ist dieselbe, sonst lässt
  sich abfragen, ob eine Praxis gerade eine PIN offen hat.
- **Drosselung nur je PIN** hilft nicht gegen einen Betreiber, der bei
  hundert Praxen je vier Versuche macht.
- **Der Hinweis in der Praxis über `acrossTenants()`** würde bei jeder
  Anfrage jedes Praxisbenutzers einen Querzugriff schreiben, dieselbe Flut,
  die WP-34a gerade abgestellt hat.
