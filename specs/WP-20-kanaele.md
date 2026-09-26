# WP-20 · Kanäle (WhatsApp, E-Mail)

## Ziel
Die vier Kanäle sprechen wirklich — und zwar jeder durch **dieselbe** Strecke,
die WP-19 gebaut hat.

## Vorher lesen
- `docs/integrationen/meta.md` — vollständig; für diese Session besonders
  **WhatsApp** (Kostenmodell, Service-Fenster, Templates, Opt-in,
  Qualitätsbewertung) und **Rate Limits und Fehler**
- `specs/WP-19-kanal-infrastruktur.md` — die Naht: `Kanaleingang`,
  `Kanalversand`, die beiden Register
- `docs/entscheidungen.md` — **A13**, **A14**, **B7**, **B8**, **C7**, **D5**, **P8**
- `CLAUDE.md`, Regeln 3, 4 und 5

## Voraussetzungen
WP-19. Und, anders als dort: **die Berechtigungen aus WP-00.** Hier beißen
sie. Gebaut und geprüft wird gegen eine eigene Gegenstelle; produktiv
sprechen kann erst, wer `whatsapp_business_messaging` und
`whatsapp_business_management` freigegeben bekommen hat.

## Zwei Sessions statt vier (Entscheidung P11, 16.09.2026)

Ursprünglich waren es vier: WhatsApp, Messenger, Instagram, E-Mail.
**Messenger und Instagram sind zurückgestellt.** Das sind zwei Kanäle
weniger in der App Review — `pages_messaging`, `pages_manage_metadata`,
`instagram_basic` und `instagram_manage_messages` entfallen dort —, und die
Zielgruppe erreicht ihre Patientinnen über WhatsApp.

| | Session | Was dazukommt |
|---|---|---|
| **a** | **WhatsApp** | Templates, Kostenkategorie, Opt-in, Statusrückmeldungen |
| **b** | **E-Mail** | eigener Empfangsweg, kein Service-Fenster, Threading |

**WhatsApp zuerst**, weil WP-19 für ihn gebaut wurde: Service-Fenster,
Kostenkategorie und Fehlertabelle stammen aus seinem Abschnitt des
Leitfadens. Wer mit einem anderen Kanal anfängt, prüft die Mechanik nicht
dort, wo sie herkommt.

**E-Mail danach**, weil er der einzige ohne Meta ist: kein
`X-Hub-Signature-256`, kein Fenster, keine Kategorie. Er ist damit die
ehrlichste Gegenprobe auf die Frage, ob die Schnittstelle aus WP-19 wirklich
eine Kanalschnittstelle ist oder eine Meta-Schnittstelle mit anderem Namen.
Diese Frage will man spät stellen, nicht früh — und mit nur zwei Kanälen
wird sie sofort gestellt.

**Was das Zurückstellen nicht bedeutet.** `ChannelType` behält die Fälle
`messenger` und `instagram`: eine Praxis darf die Instagram-Kennung einer
Person erfassen (WP-16), und `channel_identities` bildet scoped IDs weiter
ab. Bedient werden diese Kanäle nicht — kein Leser, kein Versand. Eine
Zustellung von dort erzeugt nur dann ein Rohereignis, wenn jemand eine
Verbindung dafür angelegt hat, und das tut niemand.

---

# Session a · WhatsApp

## Was dieser Kanal mehr braucht als die Strecke

**Die Kostenkategorie steht nicht in der Antwort auf den Versand.** Der
Leitfaden verlangt sie „aus der API-Antwort", und genau das liefert die Cloud
API — nur nicht dort, wo man sie vermutet: die Sendeantwort enthält
`messages[0].id`, mehr nicht. `pricing.category` kommt in der
**Statusrückmeldung**, Minuten später, als eigenes Webhook-Ereignis.

Daraus folgt beides: Statusrückmeldungen gehören in diese Session, nicht in
eine spätere. Und eine frisch gesendete Nachricht hat **keine** Kategorie —
nicht `none`, sondern unbekannt. `none` wäre eine Schätzung, und zwar die
teuerste von allen: sie sagt „kostenlos".

**Das Fenster ist eine Absage, keine Warnung.** Außerhalb der 24 Stunden
nimmt WhatsApp nur ein genehmigtes Template an. Der Versand lehnt dann ab —
vor dem Aufruf, mit klarem Grund, ohne Wiederholung. Eine Nachricht, die
draußen als „gesendet" gilt und nie ankommt, fällt erst auf, wenn niemand
antwortet.

**Opt-in gilt für das Template, nicht für die Antwort.** Wer uns schreibt,
hat sich damit gemeldet; die Antwort im Fenster braucht keinen weiteren
Nachweis. Ein Template **außerhalb** des Fensters ist eine Ansprache von
unserer Seite und verlangt eine nachweisbare Einwilligung (WP-18,
`ConsentType::WhatsApp`).

## Schritte

1. `whatsapp_templates` — Name, Sprache, Kategorie, Zustand, Rumpf.
   Genehmigt wird bei Meta, gelesen wird hier.
2. `messages.template_id` und `template_variables` — verschlüsselt, denn
   dort stehen Name und Uhrzeit einer Person.
3. `channel_connections.sender_id` — die Rufnummern-ID. Die Zustellung trägt
   die WABA-Kennung, gesendet wird unter der Rufnummer.
4. `WhatsAppEingang` — `messages[]` in allen Formen, die vorkommen, nicht nur
   `text`.
5. `Rueckmeldungsleser` und `Rueckmeldungen` — `statuses[]`, Zustand und
   Kategorie.
6. `WhatsAppVersand` — Text im Fenster, Template außerhalb, Fehlertabelle.
7. `Templateabgleich` und `mrs:whatsapp-templates`.
8. Anbindung der beiden Register.

## Abnahmekriterien

**Empfang**

1. Eine Textnachricht wird mit Absender, Inhalt und Zeitpunkt aufgenommen.
2. Der Anzeigename kommt aus `contacts[].profile.name`, nicht aus dem Text.
3. Der Zeitstempel ist in **Sekunden**, nicht in Millisekunden.
4. Ein Bild wird mit Medientyp und Bildunterschrift aufgenommen.
5. Eine Reaktion erscheint mit dem Emoji, nicht als leere Nachricht.
6. Eine Antwort auf eine Schaltfläche erscheint mit ihrer Beschriftung.
7. Ein Standort erscheint als Medientyp, **ohne** Koordinaten (Regel 3).
8. Ein Systemereignis erzeugt keine Nachricht.
9. Zwei Zustellungen derselben Nachricht erzeugen eine, nicht zwei.

**Statusrückmeldungen**

10. `delivered` setzt Zustand und Zeitpunkt der ausgehenden Nachricht.
11. `read` nach `delivered` setzt `read`.
12. `delivered` nach `read` setzt **nicht** zurück.
13. Die Kategorie kommt aus `pricing.category` und wird übernommen.
14. Ohne `pricing` bleibt die Kategorie unbekannt — nicht `none`.
15. `failed` hält den Kurzgrund fest, nicht die Meldung des Anbieters.
16. Eine Rückmeldung zu einer unbekannten Kennung läuft ins Leere, ohne Fehler.

**Versand**

17. Im Fenster geht ein Text als `type: text` an die Rufnummern-ID.
18. Die Kennung der Antwort landet in `external_id`.
19. Eine frisch gesendete Nachricht hat **keine** Kostenkategorie.
20. Außerhalb des Fensters wird ein Text abgelehnt, ohne Wiederholung.
21. Außerhalb des Fensters geht ein genehmigtes Template raus.
22. Ein nicht genehmigtes Template wird abgelehnt, ohne Aufruf.
23. Ohne Einwilligung geht außerhalb des Fensters nichts.
24. Im Fenster braucht die Antwort keine Einwilligung.
25. Die Fehlertabelle gilt unverändert (Rate Limit, Token, Berechtigung).

**Templates**

26. Der Abgleich legt genehmigte Templates an und nimmt abgelehnte zurück.
27. Ein zweiter Abgleich erzeugt keine Dubletten.
28. Die Variablen einer Nachricht liegen verschlüsselt.

## Nicht in dieser Session

- **E-Mail** (Session b).
- **Medien herunterladen.** Der Medientyp wird festgehalten, die Datei nicht
  geholt — das braucht den Media-Endpunkt und die Virenprüfung aus WP-18 und
  ist eine eigene Naht.
- **Die Inbox-Oberfläche** (WP-21). Geprüft wird über die Strecke, nicht über
  einen Bildschirm.
- **Abrechnung** (WP-06). Die Kategorie wird festgehalten, nicht in Rechnung
  gestellt.
- **Der Agent** (WP-22 bis WP-24). Regel 5 gilt trotzdem.

## Fallstricke

- **Die Sendeantwort kennt die Kosten nicht.** Wer sie dort sucht, schreibt
  am Ende `none` hin — und das ist die Schätzung, die der Leitfaden verbietet.
- **WhatsApp zählt Sekunden, Messenger Millisekunden.** Derselbe Feldname,
  drei Größenordnungen Unterschied.
- **Die Zustellung trägt die WABA-Kennung, der Versand die Rufnummern-ID.**
  Zwei Kennungen, eine Verbindung.
- **Ein Template ist je Sprache genehmigt**, nicht je Name.
- **Das Fenster schließt sich still.** Es gibt kein Ereignis dafür; wer nicht
  vor dem Senden nachsieht, erfährt es aus einer Fehlermeldung.

## Stand · Session a

Alle 28 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Kanaele/WhatsApp*Test.php` — **36 Tests**. Gesamtstand 631.

Neu: `whatsapp_templates`, `messages.template_id` und `template_variables`,
`channel_connections.sender_id`, `WhatsAppEingang`, `WhatsAppVersand`,
`Templateabgleich`, `mrs:whatsapp-templates` (täglich 04:45) und
`KanalServiceProvider` — der eine Ort, an dem sich Kanäle eintragen.

An der Naht aus WP-19 kam **eine** Schnittstelle dazu:
`Rueckmeldungsleser`. Sie ist getrennt von `Kanaleingang`, weil eine
Rückmeldung keine Nachricht ist: sie erzeugt keinen Verlaufseintrag, legt
keine Konversation an und öffnet **kein** Service-Fenster. Ein Kanal, der
keine Rückmeldungen kennt, setzt sie nicht um — E-Mail wird so einer sein.

**Geprüft wurde gegen eine eigene Gegenstelle**, nicht gegen Meta. Ob
produktiv gesprochen werden darf, entscheiden die Berechtigungen aus WP-00,
nicht dieser Code.

## Was das Bauen zutage gefördert hat

**Die Sendeantwort kennt die Kosten nicht.** Der Leitfaden verlangt die
Kategorie „aus der API-Antwort", und genau das liefert die Cloud API — nur
nicht dort, wo man sie sucht: die Antwort auf den Versand trägt
`messages[0].id` und sonst nichts. `pricing.category` kommt Minuten später
mit der Statusrückmeldung.

Daraus folgten drei Änderungen an WP-19, alle in dieselbe Richtung:
`Versandergebnis::$kategorie` ist jetzt **optional**, `vermerkeErfolg()`
überschreibt eine bekannte Kategorie nicht mehr mit `null`, und eine frisch
gesendete Nachricht hat **keine** Kategorie. Der alte Vorgabewert
`MessageCostCategory::None` war eine Schätzung mit der Aussage „kostenlos" —
die teuerste von allen, weil sie nie auffällt.

**Ein zweites `Http::fake()` auf dasselbe Muster ersetzt das erste nicht.**
Die erste passende Attrappe gewinnt. Der Test für den zweiten
Templateabgleich prüfte damit zweimal dieselbe Antwort und war grün, obwohl
nichts nachgetragen wurde. Aufgefallen ist es nur, weil die Zählung
„geändert: 1" verlangte. Zwei Antworten nacheinander brauchen
`Http::sequence()`.

**`messages.template_id` brach `json_encode()`**, gefunden vom
Architekturtest aus WP-03: eine `BINARY(16)`-Spalte, die weder verborgen noch
gecastet ist, geht als Rohbytes in die Serialisierung. Der Test hat den
Fehler gemeldet, bevor eine Oberfläche ihn zeigen konnte.

**Der Testfall „ein Rohereignis ohne Leser" stand auf WhatsApp.** Seit dieser
Session hat WhatsApp einen Leser, und der Test wurde grün aus dem falschen
Grund — er prüfte nichts mehr. Er steht jetzt auf E-Mail und wandert weiter,
bis Session b ihn heimatlos macht. Dann ist die Aussage „ein unbekannter
Kanal bleibt liegen" nicht mehr belegbar, und das ist der richtige Zeitpunkt,
sie durch einen ausdrücklichen Stellvertreter zu ersetzen.

## Offen nach Session a

**E-Mail** (Session b).

**Medien herunterladen.** Der Medientyp steht, die Datei nicht. Dafür braucht
es den Media-Endpunkt, die Virenprüfung aus WP-18 und eine Frist — eine
eigene Naht.

**Die Kostenanzeige in der Inbox** (WP-21). Die Kategorie wird festgehalten,
die Anzeige „dieses Template kostet" baut WP-21, die Abrechnung WP-06.

**Die Qualitätsbewertung** der Rufnummer. Meta meldet sie über einen eigenen
Webhook-Ereignistyp; die Spalten dafür gibt es noch nicht.

---

# Session b · E-Mail

## Die Gegenprobe

Dieser Kanal wurde nicht zuletzt gebaut, weil er der kleinste wäre, sondern
weil er die Frage beantwortet, die seit WP-19 offensteht: **ist die
Schnittstelle eine Kanalschnittstelle oder eine Meta-Schnittstelle mit
anderem Namen?**

E-Mail hat keine Signatur von Meta, kein Service-Fenster, keine
Kostenkategorie, keine Statusrückmeldungen und keine Templates. Was daran
nicht passt, ist ein Befund über die Strecke — nicht über die E-Mail.

Die Einzelheiten stehen in `docs/integrationen/email.md`.

## Abnahmekriterien

**Eingang**

1. Eine Zustellung ohne Geheimnis wird abgewiesen, ohne etwas zu speichern.
2. Ein leeres Geheimnis lässt niemanden durch.
3. Quittiert wird vor der Verarbeitung; die läuft auf der Queue `realtime`.
4. Der Mandant wird über die Eingangsadresse gefunden, nicht über die Anfrage.
5. Eine Mail an eine unbekannte Adresse bleibt liegen.

**Die Mail**

6. Absender, Anzeigename, Betreff und Text werden aufgenommen.
7. Der Betreff liegt verschlüsselt.
8. Dieselbe Mail zweimal erzeugt eine Nachricht, nicht zwei.
9. Aus HTML bleibt der Text — kein Skript, keine externe Adresse.
10. Es wird **kein** Service-Fenster geöffnet.
11. Eine Anweisung im Text löst nichts aus (Regel 5).

**Anhänge**

12. Ein Anhang geht über den Anhangspeicher, mit Ablaufdatum (C6).
13. Ein zu großer Anhang wird nicht aufgenommen, die Nachricht schon.
14. Ein Pfad im Dateinamen wird verworfen.

**Versand**

15. Die Antwort geht an den Absender, im Namen der Praxis, mit `Reply-To`
    auf die Eingangsadresse.
16. Sie trägt `In-Reply-To` und `References` der letzten eingehenden Mail.
17. Sie trägt eine eigene Message-ID, und die steht in `external_id`.
18. Der Betreff ist der des Gesprächs mit `Re:`, ohne Verdopplung.
19. Ohne Betreff greift ein Vorgabetext.
20. `cost_category` ist `none` — und das ist hier keine Schätzung.
21. Gesendet wird ohne Fenster und ohne Opt-in.
22. Eine Kennung, die keine Adresse ist, wird abgelehnt.
23. Der Versand läuft über die Queue, nie im Anfragezyklus (Regel 4).

## Stand · Session b

Alle 23 Abnahmekriterien laufen: `tests/Feature/Kanaele/Mail*Test.php` —
**24 Tests**. Gesamtstand 655.

Neu: `messages.subject` (verschlüsselt), `Mailleser`, `EmailEingang`,
`EmailVersand`, `MailEingangController` unter `POST /webhooks/mail`, der
Konfigurationsblock `mrs.channels.email` und die Abhängigkeit
`zbateson/mail-mime-parser`.

**Nachgetragen: das eigene Postfach der Praxis.** Der erste Entwurf
verschickte alles über den Plattform-Mailer — mit der Adresse der Praxis im
Absender, aber aus fremder Infrastruktur, und damit auf Gedeih und Verderb
den DNS-Einträgen je Kunde ausgeliefert. Jetzt trägt `channel_connections`
die SMTP-Zugangsdaten (Benutzername und Passwort verschlüsselt),
`App\Kanaele\Email\Postfach` baut den Mailer je Verbindung, und *Einstellungen
→ Postfach* macht beides bedienbar, samt Probemail über die Queue. Ohne
hinterlegte Zugangsdaten bleibt der Versand über die Plattform — kein
Notbehelf, sondern der Weg für eine Praxis, die gerade erst anfängt.
14 weitere Tests (`PostfachTest`), Gesamtstand 669.

## Was die Gegenprobe ergeben hat

**Drei Namen trugen Meta in die Strecke hinein.** Sie heißen jetzt nach dem,
was sie tun:

| vorher | jetzt |
|---|---|
| `meta_raw_events` | `channel_raw_events` |
| `MetaRawEvent` | `ChannelRawEvent` |
| `MetaEreignisVerarbeiten` | `RohereignisVerarbeiten` |
| `Metafehler` | `Fehlereinordnung` (Meta-Zuordnung als `ausMetaAntwort()`) |

Der Auslöser war wörtlich: der E-Mail-Versand, der nie mit Meta spricht,
musste einen `Metafehler` werfen. Ein Name, der einen Anbieter in eine
Schnittstelle trägt, in die er nicht gehört. `MetaSignatur` und der Endpunkt
`/webhooks/meta` heißen weiterhin so — die sind wirklich Metas.

**Das Service-Fenster gehörte nicht in die Konversation, sondern an den
Kanal.** `Konversationen::oeffneFenster()` setzte bei jeder eingehenden
Nachricht 24 Stunden — auch für E-Mail, wo es keines gibt. Ein Ablaufdatum,
das niemand gesetzt hat, hätte die Inbox angezeigt und die Kostenanzeige
mitgezählt. Jetzt entscheidet `ChannelType::hatServicefenster()`.

**Der Testfall „ein Rohereignis ohne Leser" wanderte zum zweiten Mal.** Er
stand auf WhatsApp, dann auf E-Mail, und wurde jedes Mal grün, ohne noch
etwas zu prüfen. Er steht jetzt dauerhaft auf **Telefon** (Entscheidung P3:
kein Telefon, kein Voice-Agent) und sagt seine Voraussetzung ausdrücklich an,
statt sich einen Kanal zu borgen, der bald einen Leser bekommt.

**`Eingangsnachricht` hat zwei Felder mehr**: `betreff` und `anhaenge`. Kein
Meta-Kanal hat ein Betrefffeld — es steht deshalb nicht im Inhalt, sondern
daneben, und bleibt bei WhatsApp leer.

## Offen nach Session b

> **Nachtrag 26.09.2026.** **Medien bei WhatsApp** werden geholt
> (`MedienHolen`, `WhatsAppMedien`; das Token geht nur an Meta), die
> **Einrichtung** steht unter *Einstellungen → WhatsApp* samt Abonnement der
> Zustellungen. Offen: die Qualitätsbewertung der Rufnummer, SPF/DKIM je Kunde
> (Betrieb).

**Medien bei WhatsApp** — der Weg über den Anhangspeicher steht jetzt und
wäre für WhatsApp derselbe; es fehlt der Media-Endpunkt.

**SPF und DKIM je Kunde** sind Betriebsarbeit, kein Code. Auf die
Einrichtungsliste gehören sie trotzdem: die Praxis merkt es zuerst — daran,
dass niemand antwortet.

**Die Oberfläche** (WP-21). Einen Kanal einzurichten geht bisher nur über die
Datenbank; eine Eingangsadresse zu vergeben gehört in die Verwaltung.
