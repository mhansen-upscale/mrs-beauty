# WP-45 · Behandlungsräume

> „Innerhalb eines Standorts auch die Behandlungszimmer pflegen", für das
> Kapazitätsmanagement, „das macht zum Beispiel Kliniko". Entscheidung
> **D16**: Räume ordnen zu, sperren nicht. Die Verfügbarkeits-Engine bleibt,
> wie sie ist.

## Ziel
Jeder Standort führt seine Behandlungsräume. Jeder Termin hat einen Raum,
vorgeschlagen oder von Hand gesetzt. Kalender und Auslastung (WP-46) zeigen,
welcher Raum wann belegt ist.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussage 12
- `docs/fachlogik/verfuegbarkeit.md`, Einleitung: warum der Behandler die einzige Ressource ist
- `specs/WP-08-praxisstammdaten.md`: Standorte ohne Öffnungszeiten (Z. 150-152)
- Entscheidungen **D16, A2, A3**; CLAUDE.md **Regel 1** und **Regel 3** (neutraler Kalendertitel)

## Voraussetzungen
WP-40. WP-43 sollte stehen, weil der Termindialog dann ohnehin umgebaut ist.

## Die Linie, an der alles hängt

**Ein Raum ist eine Angabe, keine Ressource.** Die Engine rechnet weiter nur
mit Behandlern. Ein Raum kommt **nach** der Zeitwahl dazu: Das Produkt
schlägt einen vor, ein Mensch kann ihn ändern. Belegen zwei Termine denselben
Raum zur selben Zeit, sagt das Produkt es, verhindert es aber nicht (D16).
Das ist ehrlich, solange es an jeder Stelle so dasteht. Kein Text darf
„Raum reserviert" sagen.

**Geeignet ist keine Pflicht.** Eine Terminart kann geeignete Räume nennen,
etwa den Laser im Raum 2. Nennt sie keine, ist jeder Raum am Standort
geeignet.

## Schritte
1. Abnahmekriterien als Tests.
2. **Schema:**
   - `rooms`: `id`, `organization_id`, `location_id`, `name`, `is_active`, `sort`; `TenantModel`, Fremdschlüssel `(location_id, organization_id)` (A2), wie `ArchitekturTest` es verlangt
   - `appointment_type_room` (Pivot, geeignete Räume)
   - `appointments.room_id`, nullable, mit zusammengesetztem Fremdschlüssel
   - Ein Raum gehört zu genau einem Standort.
3. **Pflege unter *Standorte*:**
   - je Standort eine Liste der Räume, anlegen, umbenennen, deaktivieren
   - Löschen nur ohne künftige Termine; sonst deaktivieren
   - geeignete Räume an der Terminart (`katalog/Terminarten.vue`)
4. **Vorschlag:** `Raumvorschlag::fuer(Appointment)`:
   - der erste aktive, geeignete Raum am Standort des Termins, der zu dieser Zeit frei ist (Termin plus Puffer, wie `belegteDauer()`)
   - ist keiner frei, der erste geeignete, mit Hinweis „doppelt belegt"
   - gibt es keine Räume, bleibt `room_id` leer
5. **Wo der Vorschlag greift:**
   - im internen Termindialog als vorbelegte Auswahl
   - für Buchungsseite, Assistent und Warteliste nach dem Commit (`afterCommit`), damit eine Buchung nie an einem Raum scheitert
6. **Kalender** (`pages/termine/Index.vue`):
   - Raum im Termin sichtbar, Filter nach Raum neben dem Standortfilter
   - Doppelbelegung als Hinweis am Termin
   - Raum ändern im Termin, mit Protokoll (WP-05)
7. **Ausgehende Kalendereinträge:** Der neutrale Titel bleibt (Regel 3, B4). Der Raumname darf ins Ortsfeld, weil er weder Kontakt noch Behandlung nennt; abschaltbar je Praxis.

## Abnahmekriterien
1. Ein Raum eines fremden Standorts lässt sich keinem Termin zuordnen; ein Raum einer fremden Praxis ergibt 404 (Regel 1, A2).
2. Mit zwei Räumen am Standort und einem Termin 10:00–10:30 in Raum 1 schlägt das Produkt für einen zweiten Termin 10:15 Raum 2 vor.
3. Sind beide Räume belegt, wird der erste geeignete vorgeschlagen, der Termin trotzdem gespeichert, und Kalender wie Termin zeigen „doppelt belegt".
4. Puffer zählen mit: Ein Termin 10:00–10:30 mit 10 Minuten Nachbereitung belegt den Raum bis 10:40.
5. Nennt die Terminart Raum 2 als einzigen geeigneten, wird nie Raum 1 vorgeschlagen.
6. Eine Online-Buchung bekommt ihren Raum nach dem Commit. Eine Ausnahme im Vorschlag lässt die Buchung bestehen, und `room_id` bleibt leer.
7. Ein Standort ohne Räume verhält sich wie heute: kein Raumfeld, keine Hinweise.
8. Ein deaktivierter Raum wird nicht mehr vorgeschlagen; bestehende Termine behalten ihn.
9. Der ausgehende Kalendereintrag trägt den Raumnamen im Ortsfeld, den neutralen Titel und keinen Kontaktnamen. Ist die Einstellung aus, steht kein Raum darin.
10. Die Verfügbarkeit bleibt bei vollem Raum gleich: `freieStartzeiten()` liefert mit und ohne Räume dieselben Zeiten (`Verfuegbarkeit/AbfrageTest` unverändert grün).
11. Die Raumänderung an einem Termin steht im Protokoll mit altem und neuem Raum, ohne Personendaten (C5).

## Nicht in diesem Paket
- Räume als Sperre in der Engine; das wäre eine neue Entscheidung, die D16 ablöst, mit eigener Fachlogik.
- Geräte als eigene Ressource (Laser, Kryo); ein Gerät, das fest in einem Raum steht, ist über „geeignete Räume" abgebildet.
- Raumpläne, Grundrisse, Öffnungszeiten je Raum.
- Auslastung; die kommt mit WP-46.

## Fallstricke
- **Keine Sperre heißt auch kein `FOR UPDATE` auf Räumen.** Zwei gleichzeitige Buchungen können denselben Raum vorgeschlagen bekommen. Das ist nach D16 erlaubt, aber der zweite muss den Hinweis tragen. Deshalb rechnet der Vorschlag nach dem Commit noch einmal, statt einen im Request gerechneten Wert zu übernehmen.
- **Der Assistent nennt keinen Raum.** Eine Zusage „in Raum 2" wäre ein Versprechen, das D16 nicht hält.
- **Exchange-Raumpostfächer** (WP-15 schließt sie aus) sind etwas anderes. Ein Raum hier ist kein Kalender und wird nicht synchronisiert.
