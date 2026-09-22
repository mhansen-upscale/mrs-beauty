# Produkt

> **Rekonstruktion, dünn.** Dieses Dokument fehlte im Repository.
> `specs/WP-30-hwg-compliance.md` verweist ausdrücklich auf den Abschnitt
> „Das Differenzierungsmerkmal". Nur dieser Abschnitt lässt sich aus den
> vorhandenen Spezifikationen belastbar rekonstruieren. Der Rest ist als
> Gerüst angelegt und **vom Produktverantwortlichen zu füllen**.

## Wofür das Produkt da ist

Eine Praxis für ästhetische Behandlungen führt Werbung, Kommunikation und
Termine heute in getrennten Systemen. Das Produkt führt sie zusammen und
schließt damit eine Kette, die sonst niemand schließt:

```
Anzeige → Klick → Besucher → Lead → Termin → erschienen → Behandlung → Umsatz
```

**Das ist die Zahl, die das Abo rechtfertigt** (`docs/fachlogik/attribution.md`).

## Das Differenzierungsmerkmal

Werbung für ästhetische Eingriffe ist in Deutschland enger reguliert als fast
jede andere Werbung. Zwei Ebenen greifen gleichzeitig:

**Meta** behandelt kosmetische Verfahren als eingeschränkte Kategorie. Zu
erwarten sind Auflagen bei Alters-Targeting und Standort sowie Ablehnungen bei
Vorher-Nachher-Darstellungen.

**Deutsches Recht ist strenger.** Der BGH hat mit Urteil vom 31.07.2025
(I ZR 170/24) Vorher-Nachher-Bilder auch für minimalinvasive Eingriffe
verboten — Botox und Hyaluron eingeschlossen, nicht nur klassische
Operationen. Verstöße können mit bis zu 50.000 Euro geahndet werden.

Daraus folgen drei Dinge, die das Produkt von jedem Mitbewerber trennen:

1. **Die Oberfläche erzwingt die Anforderungen, statt die API-Ablehnung
   anzuzeigen.** Ein Kunde, der eine Kampagne baut und beim Speichern eine
   Meta-Fehlermeldung bekommt, hält das Produkt für kaputt.
2. **Die HWG-Prüfung läuft vor jeder Übermittlung an Meta**, nicht danach
   (WP-30).
3. **Eine Bibliothek rechtssicherer Alternativformate** gehört dazu: die
   Prüfung sagt nicht nur, was nicht geht, sondern was stattdessen geht —
   Arzt-Vorstellung, Ablauf-Erklärung, Räumlichkeiten, Preis- und
   Risikotransparenz.

**Das Produkt ist eine Prüfhilfe, keine Rechtsberatung.** Formulierung und
Haltung müssen das durchgängig widerspiegeln, sonst entsteht eine Haftung,
die niemand tragen will.

## Was der Agent ist und was nicht

Eine Empfangskraft, keine medizinische Fachkraft. Er nimmt Anfragen entgegen,
beantwortet organisatorische Fragen und bucht Termine. Alles darüber hinaus
geht an Menschen. Siehe `docs/fachlogik/agent.md`.

## Zu füllen

Diese Abschnitte werden von den Arbeitspaketen nicht referenziert und fehlen
hier deshalb vollständig:

- Zielkunde und Marktabgrenzung
- Preismodell und Abo-Stufen, insbesondere die Behandlung der
  WhatsApp-Kosten (siehe `docs/integrationen/meta.md`: WhatsApp darf im Abo
  nicht unbegrenzt sein)
- Onboarding einer neuen Praxis
- Abgrenzung gegenüber bestehenden Praxisverwaltungssystemen
