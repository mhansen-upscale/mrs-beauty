# WP-41 · Kampagnenstrecke reparieren, HWG nachschärfen

> Vor dem Kampagnen-Assistenten (WP-42) muss die Strecke darunter tragen. Die
> Durchsicht für Feedbackschleife 1 hat fünf Lücken gefunden, an denen eine
> im Produkt angelegte Anzeige nicht ausgeliefert oder nicht zugeordnet würde.
> Dazu kommen zwei HWG-Befunde aus dem Video. WP-27b sagt selbst: „Gegen die
> echte Marketing-API ist nichts geprüft."

## Ziel
Eine im Produkt angelegte Kampagne mit Anzeige läuft nach „Starten" bei Meta
tatsächlich. Sie endet zum gesetzten Datum, und jede Buchung aus ihr ist der
Kampagne zugeordnet. Das HWG-Regelwerk erkennt Werbung für
verschreibungspflichtige Arzneimittel.

## Vorher lesen
- `docs/integrationen/meta.md`, ganz
- `docs/fachlogik/attribution.md`, Abschnitt Berührungen
- `specs/WP-27-kampagnenverwaltung.md`, `WP-27b-anzeigen-schalten.md`, `WP-30-hwg-compliance.md`, `WP-32a-attribution-erfassung.md`
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Beobachtungen B6 und B7
- CLAUDE.md, **Regeln 2 und 4**; Entscheidungen **C2, C3, C9, C13, C18**

## Voraussetzungen
WP-26 bis WP-32a stehen. Ein Werbekonto mit `ads_management` und einer
Facebook-Seite für den Abgleich gegen Meta (Sandbox oder eigenes Konto).

## Die Lücken

1. **Nur die Kampagne wird aktiv.** Anzeigengruppe und Anzeige entstehen `PAUSED` (`Kampagnenverwaltung.php:220`, `Anzeigenschaltung.php:145`).
   „Starten" schickt nur Status und Budget der Kampagne (`Kampagnenverwaltung.php:336-352`); `Anzeigenschaltung::passeAn()` (Z. 154) ruft niemand auf.
   Bei Meta liefert eine aktive Kampagne mit pausierter Gruppe nichts aus. Der Dialog sagt trotzdem „Starten können Sie sie unter Kampagnen" (`anzeigen/Index.vue:928`).
2. **Beginn und Ende gehen nicht hinaus.** Sie werden lokal gespeichert (`Kampagnenverwaltung.php:65-66, 87-88`), aber weder an die Kampagne (117-134) noch an die Gruppe (218-258) gesendet. Der nächste Abgleich überschreibt sie (`Strukturabgleich.php:99-100`). Ein Enddatum beendet nichts.
3. **Der Anzeigenlink trägt keine Zuordnung.** Der Link ist die nackte Buchungsseite (`Anzeigenschaltung.php:348, 434-443`). `Beruehrungen.php:46-48` liest `mrs_campaign`, `mrs_adset` und `mrs_ad`, aber niemand setzt sie (WP-32a, Z. 150-152 und 229).
4. **„Anfragen sammeln" ohne Lead-Formular.** `OUTCOME_LEADS` verlangt `LEAD_GENERATION` (`mrs.php:970-985`), die Anzeige führt aber auf die Website. Wahrscheinlich lehnt Meta das an der Anzeige ab; ungeprüft. Das Ziel, das tut, was die Anzeige tut, heißt „Besuche auf der Buchungsseite" (`OUTCOME_TRAFFIC`, `LANDING_PAGE_VIEWS`).
5. **Jede Grafik braucht eine Freitext-Übersteuerung.** `VorherNachher.php:55-62` gibt bei jedem Bild Gelb, und freigeben lässt sich nur Grün (`Ampel.php:32-35`). Also geht jede Anzeige über „Übersteuern" mit mindestens zehn Zeichen Begründung (`AnzeigenController.php:526`). Im Video steht dort „Jklkkb öpöbpöhi" (B7). Eine Pflicht, die jeder mit Tastaturrauschen erfüllt, schützt niemanden.

## Die HWG-Befunde

6. **§ 10 HWG fehlt.** Publikumswerbung für verschreibungspflichtige Arzneimittel ist verboten. Botulinumtoxin ist verschreibungspflichtig, und „Botox" ist der klassische Abmahnfall. Die Demo-Anzeige „Mehr Botox für alle!" bekam nur zwei Hinweise (B6). `app/Compliance/Regeln` hat keine Regel dafür.
   Vorschlag: eine `Wortregel` mit Wirkstoff- und Produktnamen (Botox, Botulinum, Bocouture, Azzalure, Vistabel …), Rot, mit Alternativvorschlag („Faltenbehandlung", „Behandlung der mimischen Falten"). Neue Fassung des Regelwerks (C1), juristisch durchzusehen (C18).
   Hyaluron-Filler sind Medizinprodukte, kein Arzneimittel; für sie greift § 10 nicht. Das muss die Durchsicht bestätigen.
7. **Bildbestätigung statt Freitext.** Für den Gelb-Befund aus Lücke 5 gibt es eine feste Bestätigung („Das Bild zeigt kein Behandlungsergebnis, weder einzeln noch im Vergleich") statt eines freien Grundes. Sie wird mit Person und Zeit protokolliert wie jede Übersteuerung (C3), der Satz dient als Begründung. Andere Gelb-Befunde behalten die freie Begründung. Dort genügen zehn Zeichen nicht mehr: verlangt werden mindestens zwei Wörter mit Buchstaben.

## Kleinere Fehler
- Der Bearbeiten-Dialog fällt auf 15 km zurück, das Minimum ist 16 (`werbung/Index.vue:216, 228`). Richtig ist die Vorgabe aus `mrs.php` (20).
- Der Beginn wird als UTC-Datum vorbelegt (`werbung/Index.vue:177`). Richtig ist das Datum in der Zeitzone des gewählten Standorts.
- Ein Beginn in der Vergangenheit wird angenommen (`Kampagnenplan.php:66-67`). Vorschlag: frühestens heute in der Zeitzone des Standorts.
- `Kampagnenplan::zielgruppe()` (Z. 128-148) ist unbenutzt; entfernen oder in WP-42 verwenden.
- Berechtigungen:
  - `mrs.ads.scopes` und ihr Kommentar sagen „lesend, ausschließlich", die Login-Konfiguration fordert aber `ads_management` (`WerbekontoController.php:541`, `Kontenauswahl.php:27`)
  - der Kommentar ist veraltet; angleichen
  - festhalten, welcher Weg die Berechtigung wirklich anfragt
- `components.json` zeigt auf `resources/js/Components`, der Ordner heißt `components`.

## Schritte
1. **Gegen Meta messen, bevor gebaut wird:** mit `execution_options=["validate_only"]` wie am 23. und 24.09.2026. Zu messen sind:
   - `start_time`/`end_time` an der Gruppe
   - `OUTCOME_LEADS` mit Website-Link
   - `url_tags` am Creative
   - der Ergebnisvermerk kommt als Kommentar an die Stelle in `config/mrs.php`
2. Abnahmekriterien als Tests schreiben.
3. Start und Pause schalten die ganze Kette: Kampagne, ihre eigenen Gruppen und ihre eigenen Anzeigen. Fremde Gruppen und Anzeigen fasst das Produkt nicht an (WP-27). Ein Auftrag je Ebene, Reihenfolge Kampagne → Gruppe → Anzeige beim Start und umgekehrt beim Pausieren; jeder idempotent über das Merkmal.
4. `start_time` und `end_time` übertragen, beim Anlegen und beim Ändern. Der Abgleich übernimmt danach den Wert von Meta, wie bisher.
5. Zuordnung an den Link:
   - `url_tags` am Creative: `mrs_campaign={{campaign.id}}&mrs_adset={{adset.id}}&mrs_ad={{ad.id}}` (dynamische Parameter von Meta)
   - die Werte sind Meta-IDs, keine Namen (Regel 2)
   - `Beruehrungen` liest sie bereits
6. Standardziel „Besuche auf der Buchungsseite". „Anfragen sammeln" bleibt nur, wenn Schritt 1 es mit Website-Link bestätigt; sonst entfällt es aus `ads.objectives`.
7. HWG-Fassung 2 mit der Regel zu § 10, dazu Bildbestätigung (Befund 7).
8. Kleinere Fehler beheben, Texte nach WP-40.

## Abnahmekriterien
1. „Starten" einer eigenen Kampagne mit einer Gruppe und einer Anzeige ergibt drei Aufträge in der Reihenfolge Kampagne → Gruppe → Anzeige. Danach stehen alle drei bei Meta (Fake-Gegenstelle) auf `ACTIVE`.
2. „Pausieren" setzt alle drei auf `PAUSED`, in der Reihenfolge Anzeige → Gruppe → Kampagne.
3. Eine fremde Anzeige in einer eigenen Kampagne wird weder gestartet noch pausiert.
4. Ein Auftrag, dessen Antwort verloren ging, schaltet beim zweiten Lauf nicht doppelt und legt nichts an.
5. Beginn und Ende stehen im Payload der Gruppe als `start_time`/`end_time` in UTC, gerechnet aus dem Datum in der Zeitzone des Standorts. Für Hamburg bedeutet ein Ende am 31.10. das Ende des Tages Berliner Zeit.
6. Das Creative trägt `url_tags` mit genau den drei Parametern. Der Payload enthält keinen Katalognamen (`Treatment::aktiveNamen()`, wie die bestehende Prüfung).
7. Eine Buchung über einen Link mit den drei Parametern erzeugt eine Berührung mit `campaign_external_id`, `adset_external_id` und `ad_external_id` (WP-32a).
8. Eine neue Kampagne ohne gewähltes Ziel bekommt `OUTCOME_TRAFFIC` mit `LANDING_PAGE_VIEWS`.
9. Der Text „Mehr Botox für alle!" ergibt Rot mit Fundstelle § 10 HWG und einem Alternativvorschlag; „Faltenbehandlung" ergibt kein Rot aus dieser Regel.
10. Das Regelwerk trägt Fassung 2; eine Prüfung aus Fassung 1 behält ihre Fassung (C1).
11. Ein Bild mit nur dem Gelb-Befund aus `VorherNachher` lässt sich mit der festen Bildbestätigung freigeben. Das Protokoll hält Person, Zeit und Befundcode fest, keinen Text.
12. Eine freie Begründung „Jklkkböpöbpöhi" (ein Wort) wird abgewiesen; „Text juristisch abgestimmt" wird angenommen.
13. Der Bearbeiten-Dialog schlägt bei fehlendem Wert 20 km vor; ein Beginn gestern wird abgewiesen.

## Nicht in diesem Paket
- Der Assistent selbst (WP-42).
- Lead-Formulare bei Meta: Die Daten blieben bei Meta, mit einem neuen Regel-2-Weg.
- Eine Prüfung der Bildinhalte durch ein Modell. Gelb bleibt Gelb; die Bestätigung bleibt ein Mensch.

## Fallstricke
- **Die Reihenfolge ist nicht kosmetisch.** Meta liefert nur aus, wenn alle drei Ebenen aktiv sind. Gestartet wird von oben, pausiert von unten. So fällt ein Abbruch mitten in der Kette immer auf „liefert nicht aus" und nie auf „läuft weiter, obwohl pausiert". Jede Ebene ist ein eigener Auftrag in einer Kette (`Bus::chain`), nicht parallel.
- **`url_tags` statt angehängter Link.** Ein Link mit fest eingesetzten IDs bräuchte die Anzeige-ID vor dem Anlegen der Anzeige. Die dynamischen Parameter setzt Meta selbst.
- **§ 10 HWG gilt für den Text, nicht für die Praxis.** Eine Praxis darf Botox anwenden und auf ihrer Website sachlich nennen; bewerben darf sie es gegenüber Laien nicht. Die Buchungsseite ist Prüfgegenstand (C11). Eine Leistung „Erstberatung Botox" würde dort mit Fassung 2 rot. **Vor dem Ausrollen** die Praxen mit solchen Katalognamen zählen und informieren.
- **Fassung 2 macht keine bestehende Prüfung ungültig.** Neu geprüft wird beim nächsten Speichern oder Freigeben, wie bei jeder Fassung (C1).
