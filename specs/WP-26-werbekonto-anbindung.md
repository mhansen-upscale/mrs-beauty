# WP-26 · Werbekonto-Anbindung & Sync

## Ziel
Die Praxis verbindet ihr Meta-Werbekonto, und das Produkt kennt danach ihre
Kampagnenstruktur — ohne selbst etwas zu ändern.

## Vorher lesen
- `docs/integrationen/meta.md` — vollständig, besonders *Werbekonten*,
  *API-Version*, *Rate Limits und Fehler*, *Datenschutz*
- `docs/datenmodell.md`, Abschnitt 7
- `docs/entscheidungen.md` — **B1** Werbekonto gehört dem Kunden, **B2** kein
  Live-Aufruf im Request-Zyklus, **A4** Schlüssel, **C9** Grenze zwischen
  Angebot und Person
- `CLAUDE.md`, Regeln 1, 3 und 4

## Voraussetzungen
WP-03 (Mandantentrennung, Verschlüsselung), WP-19 (Fehlereinordnung,
Verbindungszustände), WP-33 (Betriebslage).

**Nicht vorausgesetzt: WP-00.** Dieses Paket lässt sich ohne App Review bauen
und ist umgekehrt die Demo, die der Review verlangt. Gelesen wird gegen das
eigene Werbekonto, das im Entwicklungsmodus ohne Freigabe erreichbar ist.

## Die Linie, an der alles hängt

**Dieses Paket liest. Es schreibt nichts.**

Kein Aufruf dieses Pakets legt bei Meta etwas an, ändert etwas oder löscht
etwas. Das ist nicht nur Abgrenzung zu WP-27, sondern die Bedingung dafür,
dass es vor dem App Review gebaut werden kann: `ads_read` und
`business_management` sind die Berechtigungen, die durchgehen;
`ads_management` ist die, die abgelehnt wird.

Die Gegenprobe ist ausführbar: **unter `app/Werbung` existiert kein
schreibender HTTP-Aufruf** — kein `post`, kein `delete`, kein `->put(`
Richtung Graph. Ein Test hält das fest, sonst wandert die erste
Bequemlichkeit aus WP-27 rückwärts in dieses Paket.

> **Nachtrag aus WP-27.** Die Regel ist enger geworden, nicht aufgehoben: eine
> einzige, im Test namentlich genannte Datei
> (`Verwaltung/Graphschreiber.php`) darf schreiben, der Rest bleibt
> schreibfrei. Eine Ausnahmeliste mit einem Eintrag ist eine Regel; eine ohne
> Liste ist keine — deshalb prüft der Test auch, dass der Eintrag existiert.

## Zwei Dinge, die dieses Paket entscheidet

### Der Verbindungszustand gehört nicht dem Kanal

`ChannelConnectionStatus` heißt nach dem Kanal, beschreibt aber einen
Verbindungszustand: `active`, `degraded`, `expired`, `suspended`. Ein
Werbekonto hat genau diese vier, und es ist kein Kanal.

Das ist derselbe Fund wie in WP-20b, wo `MetaRawEvent` zu
`ChannelRawEvent` wurde: ein Name, der einen Zusammenhang in eine
Schnittstelle trägt, in die er nicht gehört. Umbenennen zu
`ConnectionStatus`, solange es drei Aufrufstellen sind.

### Namen aus Meta liegen verschlüsselt

Eine importierte Kampagne kann „Botox Herbst" heißen — die Praxis hat sie so
benannt, bevor sie uns kannte. Wir dürfen den Namen nicht ändern, und er ist
ab WP-32 Teil des `attribution_snapshot` am Termin (D13): ein
Behandlungsname in einem offenen Feld unmittelbar neben einem Kontakt.

Deshalb tragen `ad_campaigns.name`, `ad_sets.name` und `ads.name` den
`Encrypted`-Cast. **Der Preis steht dazu:** keine Sortierung und keine Suche
über SQL. Bei einigen Dutzend Kampagnen je Praxis wird in PHP sortiert, und
das ist die billigere Hälfte des Handels — dieselbe Abwägung wie P8.

Zusätzlich, weil das an Metas Seite nichts ändert: enthält ein importierter
Name eine aktive Katalogbezeichnung, weist die Oberfläche darauf hin. Neu
angelegte Kampagnen benennt ab WP-27 das Produkt.

## Schritte

1. `ConnectionStatus` aus `ChannelConnectionStatus` — Umbenennung samt
   Aufrufstellen und Casts.
2. `ad_accounts`: `external_id` (`act_…`), Name, Währung, Zeitzone,
   Business-Kennung, Zustand, `access_token` **verschlüsselt**,
   `token_expires_at`, `last_synced_at`, `last_error`, `failed_at`.
   Ein Werbekonto je Mandant — mehr wäre eine Frage ohne Nachfrage.
3. `ad_campaigns`, `ad_sets`, `ads`: je `external_id`, Name (verschlüsselt),
   Zustand bei Meta, Ziel, Budget in kleinster Einheit, Laufzeit,
   `synced_at`, `vanished_at`. Zusammengesetzte Fremdschlüssel über
   `TenantSchema::reference()`.
4. `App\Werbung\Meta\Werbezugang` — die Login-Strecke, nach dem Muster von
   `GoogleZugang`: Weiterleitung, Code tauschen, langlebiges Token ablegen.
   **Systembenutzer-Token, kein Nutzertoken** — ein Nutzertoken stirbt mit
   dem Ausscheiden eines Mitarbeiters.
5. `App\Werbung\Kontenauswahl` — wer mehrere Werbekonten freigibt, wählt
   eines. Ein eigener Schritt, keine Rateoperation.
6. `App\Werbung\Strukturabgleich` — Kampagnen, Anzeigengruppen, Anzeigen
   lesen, mit Paging, idempotent über `external_id`. Muster:
   `Kanaele\WhatsApp\Templateabgleich`.
7. `Jobs\WerbestrukturAbgleichen` auf Queue `default`, `afterCommit`,
   Idempotenz je Werbekonto. Der Knopf *Jetzt abgleichen* stellt ein, er
   ruft nicht auf (B2).
8. `Console\Commands\Werbestruktur` als `mrs:werbung-abgleichen`, täglich im
   Planer, plus Ablaufwarnung für das Token.
9. Oberfläche: eigener Menüpunkt *Werbung* mit `campaigns.manage`.
   Nicht verbunden → Einladung. Verbunden → Konto, Zustand, letzter Abgleich,
   Kampagnenliste. Gestört → Hinweis mit dem, was zu tun ist.
10. `Betriebslage::fuerMandant()` um das Werbekonto erweitern; das
    Mandantenblatt in WP-34 zeigt es mit.

## Abnahmekriterien

**Verbindung**

1. Eine Praxis ohne Werbekonto sieht eine Einladung, keinen Fehler.
2. Die Rückkehr aus der Login-Strecke legt eine Verbindung an; das Token
   liegt verschlüsselt und steht in keiner Antwort.
3. Bei mehreren freigegebenen Werbekonten wird gewählt, nicht geraten.
4. Ein abgelaufenes Token setzt `expired` und wird **nicht** wiederholt.
5. Eine fehlende Berechtigung setzt `degraded` — mit einem anderen Hinweis
   als ein abgelaufenes Token.
6. Ein gesperrtes Werbekonto hält jeden Lauf an und ist im Produkt sichtbar.
7. Trennen entfernt das Token; die gelesene Struktur bleibt und ist als
   getrennt erkennbar.

**Abgleich**

8. Ein erster Lauf legt Kampagnen, Anzeigengruppen und Anzeigen an, ein
   zweiter legt nichts doppelt an.
9. Eine Antwort über mehrere Seiten wird vollständig gelesen.
10. Was bei Meta verschwindet, wird markiert, nicht gelöscht.
11. Eine Umbenennung bei Meta kommt an.
12. Ein Rate-Limit wird wiederholt, ein ungültiges Token nicht.
13. Der Knopf *Jetzt abgleichen* stellt einen Job ein und ruft Meta nicht im
    Request auf.
14. Die Seite lädt bei einer Meta-Störung mit dem letzten Stand und
    sichtbarem Hinweis, nicht mit einem Fehler (Regel 4).

**Regeln**

15. Kampagnen-, Anzeigengruppen- und Anzeigennamen liegen verschlüsselt.
16. Ein importierter Name mit einer aktiven Katalogbezeichnung erzeugt einen
    Hinweis in der Oberfläche.
17. **Unter `app/Werbung` existiert kein schreibender Graph-Aufruf.**
18. Zwei Mandanten sehen ausschließlich ihre eigene Struktur; kein Zugriff
    ohne Mandantenbezug (Regel 1).

## Nicht in diesem Paket

- **Kampagnen anlegen oder ändern** — WP-27, und erst mit `ads_management`.
- **Zahlen: Kosten, Reichweite, Klicks** — WP-28. Hier steht die Struktur,
  nicht ihre Wirkung.
- **Attribution, ROAS, `attribution_touches`** — WP-32.
- **Anzeigeninhalte und Vorschläge** — WP-29 bis WP-31.
- **Conversions API.** Gehört zu WP-32 und ist die Stelle, an der Regel 2
  wirklich scharf wird.

## Fallstricke

- **Ein Nutzertoken ist bequem und falsch.** Es funktioniert in der
  Entwicklung sofort und stirbt in Produktion mit dem ersten Mitarbeiter, der
  die Praxis verlässt.
- **Beträge kommen als Zeichenkette in kleinster Einheit** und in der Währung
  des Werbekontos. Wer sie als Float liest, verliert Cent; wer die Währung
  nicht mitführt, addiert später Euro und Franken.
- **Meta löscht nicht, Meta archiviert** — meistens. `effective_status` und
  `status` sind zwei verschiedene Felder, und eine Kampagne kann aktiv sein,
  während ihre Anzeigengruppe es nicht ist.
- **Paging ohne Ende.** `paging.next` läuft im Zweifel im Kreis; eine harte
  Obergrenze an Seiten gehört dazu.
- **Die Umbenennung des Zustands-Enums ist eine Migration.** Die Werte in
  `channel_connections.status` bleiben, die Klasse wandert.
- **Der Abgleich läuft über alle Mandanten.** Ein Fehler bei einem darf die
  übrigen nicht anhalten — je Werbekonto ein Job, nicht ein Lauf über alle.

## Stand

Die 18 Abnahmekriterien laufen: `tests/Feature/Werbung/WerbekontoTest.php`
(**20 Tests**) und `tests/Feature/Werbung/NurLesendTest.php` (**3 Tests**,
davon zwei Gegenproben). Gesamtstand 849 Tests, 2955 Zusicherungen.
PHPStan Stufe 8 sauber, `vue-tsc` sauber, 34 Seiten im Manifest.

Neu: `ad_accounts`, `ad_campaigns`, `ad_sets`, `ads` samt Modellen,
`App\Werbung` (`Meta\Werbezugang`, `Meta\Graphleser`, `Kontenauswahl`,
`Strukturabgleich`, `Namenspruefung`, `Werbeuebersicht`, `Abgleichbilanz`,
`Werbefehler`, `Werbetoken`, `Werbekontoangabe`), `WerbestrukturAbgleichen`,
`mrs:werbung-abgleichen` (täglich 05:15), `routes/werbung.php` und die Seite
*Werbung* mit eigenem Menüpunkt hinter `campaigns.manage`.

Umbenannt: `ChannelConnectionStatus` → `ConnectionStatus`,
`App\Kanaele\Fehlereinordnung` → `App\Support\Fehlereinordnung`.

Im Browser nachgesehen: ohne Werbekonto die Einladung, mit Demo-Daten die
Liste samt Budget in Euro, dem Hinweis auf „Botox Herbst 2026" und — nach
einem von Hand gesetzten Ausfall — der Störungshinweis auf der Seite **und**
auf dem Dashboard. Im Demo-Mandanten der Entwicklungsumgebung liegen jetzt
ein Werbekonto und drei Kampagnen als Ansichtsmaterial.

## Was das Bauen zutage gefördert hat

**Metas Zeitstempel tragen einen Versatz, und der ging verloren.**
`2026-09-01T08:00:00+0200` landete als `08:00:00` in der Spalte — zwei
Stunden daneben, entgegen der Vorgabe „gespeichert wird UTC".

Bemerkt wurde das nicht als Zeitfehler, sondern als **Zählfehler**: der
zweite Abgleich meldete dieselbe Kampagne erneut als geändert, weil der
gelesene Wert nie dem gesendeten glich. Ohne die Bilanz — die drei Zahlen
*neu, geändert, verschwunden* — wäre nichts aufgefallen; die Spalte hätte
still zwei Stunden daneben gelegen, bis in WP-28 jemand Kosten nach Tagen
ausgewertet hätte.

**Verschlüsselte Felder sind immer verändert.** Der `Encrypted`-Cast
verschlüsselt bei jedem Setzen mit neuem Initialisierungsvektor: derselbe
Klartext ergibt jedes Mal ein anderes Geheimnis, und Eloquents `isDirty()`
hält das für eine Änderung. Ein nächtlicher Lauf hätte jede Kampagne neu
geschrieben und jede Bilanz nach Bewegung aussehen lassen. Verglichen wird
deshalb der gelesene Wert, nicht der gespeicherte.

**Die Regel aus WP-05 hat sich als richtig erwiesen, wo sie unbequem war.**
„Jedes Modell mit einem `Encrypted`-Cast muss seine personenbezogenen Felder
benennen" schlug bei den drei neuen Modellen sofort an — und ein
Kampagnenname *ist* kein Personendatum. Die Ausnahme wäre billig gewesen und
hätte die Regel mit einem Loch zurückgelassen. Stattdessen tragen
`AdCampaign`, `AdSet` und `Ad` jetzt `HasPersonalData`: „Botox Herbst" ist ein
Behandlungshinweis, und eine Supportkraft sieht ihn ohne Freigabe nicht (C4).

**Der erste Architekturtest gab falschen Alarm.** Die Suche nach schreibenden
Aufrufen traf `Collection::put()`. Ein Test, der beim ersten Lauf falsch
anschlägt, wird beim zweiten abgeschaltet — gesucht wird jetzt nur in Dateien,
die überhaupt HTTP sprechen.

**Zwei Namen trugen einen Zusammenhang, in den sie nicht gehörten.**
`ChannelConnectionStatus` beschrieb einen Verbindungszustand, und ein
Werbekonto ist kein Kanal; `Fehlereinordnung` lag unter `App\Kanaele`,
obwohl ihr eigener Kommentar seit WP-20b sagt, dass der Träger allgemein ist.
Beides derselbe Fund wie damals bei `MetaRawEvent` → `ChannelRawEvent`, und
beides billiger jetzt als nach WP-27 und WP-28.

## Offen

- **Die Login-Konfiguration bei Meta.** `META_LOGIN_CONFIG_ID` entscheidet, ob
  ein Systembenutzer- oder ein Nutzertoken ausgestellt wird. Ohne sie läuft
  die Strecke über Bereiche — das trägt Entwicklung und die Demo des App
  Review, nicht den Betrieb.
- **Der App Review selbst** (WP-00): `ads_read` und `business_management`.
  Dieses Paket ist die Demo dafür.
- **Ein zweites Werbekonto je Praxis.** Bewusst nicht vorgesehen; sollte es
  je gebraucht werden, muss jede Auswertung es ab dann mitschleppen.
- **Die Zielgruppendefinition** wird nicht übernommen. Sie enthält Interessen,
  die im Umfeld einer ästhetischen Praxis gesundheitsnah sind, und dieses
  Paket braucht sie für nichts.
