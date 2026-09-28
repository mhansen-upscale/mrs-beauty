<script setup lang="ts">
import Abschnittskopf from '@/components/oeffentlich/Abschnittskopf.vue';
import Demoformular from '@/components/oeffentlich/Demoformular.vue';
import Fragen from '@/components/oeffentlich/Fragen.vue';
import Funktionszeile from '@/components/oeffentlich/Funktionszeile.vue';
import AuswertungGrafik from '@/components/oeffentlich/grafik/AuswertungGrafik.vue';
import EmpfangGrafik from '@/components/oeffentlich/grafik/EmpfangGrafik.vue';
import HeroCollage from '@/components/oeffentlich/grafik/HeroCollage.vue';
import HwgGrafik from '@/components/oeffentlich/grafik/HwgGrafik.vue';
import KalenderGrafik from '@/components/oeffentlich/grafik/KalenderGrafik.vue';
import KettenGrafik from '@/components/oeffentlich/grafik/KettenGrafik.vue';
import Preiskarte from '@/components/oeffentlich/Preiskarte.vue';
import { type Anbieter, type Preise } from '@/components/oeffentlich/typen';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import OeffentlichLayout from '@/layouts/oeffentlich/OeffentlichLayout.vue';
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    Bot,
    Building2,
    CalendarCheck,
    CalendarDays,
    Check,
    Euro,
    KeyRound,
    LockKeyhole,
    Mail,
    Megaphone,
    MessageCircle,
    Phone,
    Scale,
    ShieldCheck,
    Sparkles,
    Timer,
    UserRound,
    Users,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Startseite (WP-38): was Mrs. Beauty tut, was es kostet, wie man es
 * bekommt — und an jeder Stelle der Weg zur Demo.
 *
 * **Die Aussagen halten die Grenzen des Produkts.** Die HWG-Prüfung ist eine
 * Prüfhilfe, keine Rechtsberatung (C18); der Assistent ist eine
 * Empfangskraft, keine medizinische Fachkraft (Regel 6). Keine
 * Alleinstellung, kein „zertifiziert“, keine Präparatenamen, und beworben
 * wird nur, was steht.
 *
 * Der Titel entspricht `StartseiteController::TITEL` — sonst springt der
 * Reiter beim Laden.
 */
const props = defineProps<{
    preise: Preise | null;
    demoformular: { merkmal: string };
    anbieter: Anbieter;
}>();

const testphase = computed(() => (props.preise ? `${props.preise.testphaseTage} Tage Testphase` : 'Testphase inklusive'));

interface Punkt {
    icon: LucideIcon;
    titel: string;
    text: string;
}

const heute: Punkt[] = [
    { icon: Megaphone, titel: 'Eine Agentur', text: 'für die Werbung — und keine Zahl, was sie an Terminen bringt.' },
    { icon: CalendarDays, titel: 'Ein Buchungstool', text: 'das vom Posteingang nichts weiß.' },
    { icon: MessageCircle, titel: 'WhatsApp am Empfang', text: 'zwischen Telefon und Tresen beantwortet.' },
    { icon: Scale, titel: 'Das Heilmittelwerbegesetz', text: 'und die Sorge vor der nächsten Abmahnung.' },
];

const weitere: Punkt[] = [
    { icon: Users, titel: 'Team mit Rollen', text: 'Inhaberin, Verwaltung, Empfang, Behandlerin und Marketing — jede Rolle sieht, was sie braucht.' },
    { icon: Building2, titel: 'Mehrere Standorte', text: 'Praxisgruppen laufen als eine Organisation, mit Öffnungszeiten je Standort.' },
    { icon: Mail, titel: 'Mails aus Ihrem Postfach', text: 'Bestätigungen und Erinnerungen gehen über Ihre Adresse, mit Ihren Texten und Farben.' },
    { icon: KeyRound, titel: 'Zweiter Faktor', text: 'Anmeldung auf Wunsch mit Authenticator-App oder Code per E-Mail.' },
];

const vertrauen: Punkt[] = [
    {
        icon: LockKeyhole,
        titel: 'Jede Praxis für sich',
        text: 'Namen, Kontaktwege, Nachrichten und Notizen liegen verschlüsselt, mit einem eigenen Schlüssel je Praxis. Unser Support sieht maskierte Daten — mehr nur mit Ihrer Freigabe, und jeder Zugriff steht im Protokoll.',
    },
    {
        icon: ShieldCheck,
        titel: 'Keine Gesundheitsdaten an Meta',
        text: 'Behandlungen verlassen das System nie in Richtung Meta — nicht im Pixel, nicht in Zielgruppen, nicht in Kampagnennamen. Keine Zielgruppen aus Kontaktlisten.',
    },
    {
        icon: UserRound,
        titel: 'Ein Assistent, keine Ärztin',
        text: 'Medizinische Fragen, Beschwerden und Fotos gibt der Assistent ohne eigene Antwort an Ihr Team. Jede seiner Nachrichten ist als KI-Nachricht gekennzeichnet.',
    },
    {
        icon: Timer,
        titel: 'Fristen setzt das System',
        text: 'Für jede Datenart gilt eine feste Frist. Täglich zeigt das System, was fällig ist — gelöscht wird mit einem Klick, bewusst und nicht aus Versehen. Aus einem fremden Kalender übernimmt es nur die Zeit, nie den Titel.',
    },
];

const schritte = computed(() => [
    { titel: 'Demo-Gespräch', text: 'Wir zeigen Ihnen den Weg einer Anfrage von der Anzeige bis zum Termin und klären, was Ihre Praxis braucht.' },
    {
        titel: 'Einrichtung mit uns',
        text: 'Meta-Verifizierung, WhatsApp-Nummer, Leistungskatalog, Brand Guide und Kalender — wir richten es gemeinsam mit Ihnen ein.',
    },
    {
        titel: props.preise ? `${props.preise.testphaseTage} Tage testen` : 'Testphase',
        text: 'Ihr Team arbeitet mit echten Anfragen. Das Abo schließen Sie erst ab, wenn es passt.',
    },
    { titel: 'Monatlich weiter', text: 'Ein Paket mit allen Funktionen, monatlich kündbar.' },
]);

const fragen = computed(() => [
    {
        frage: 'Ist die HWG-Prüfung eine Rechtsberatung?',
        antwort:
            'Nein. Sie ist eine Prüfhilfe: Sie markiert Formulierungen, die nach dem Heilmittelwerbegesetz problematisch sein können, nennt die Fundstelle und schlägt eine Alternative vor. Die Verantwortung für Ihre Werbung bleibt bei Ihnen.',
    },
    {
        frage: 'Beantwortet der KI-Assistent medizinische Fragen?',
        antwort:
            'Nein. Er beantwortet organisatorische Fragen und Preise aus Ihrem Katalog und bietet Termine an. Medizinische Fragen, Beschwerden, Hinweise auf Komplikationen und Bildanhänge gibt er ohne eigene Antwort an Ihr Team weiter. Am Anfang schlägt er nur vor — abgeschickt wird, was Sie freigeben.',
    },
    {
        frage: 'Was passiert mit den Daten unserer Patientinnen und Patienten?',
        antwort:
            'Namen, Kontaktwege, Nachrichten und Notizen liegen verschlüsselt, mit einem eigenen Schlüssel je Praxis. An Meta gehen weder Behandlungen noch Kontaktlisten. Aufbewahrungsfristen setzt das System selbst durch.',
    },
    {
        frage: 'Brauchen wir ein eigenes Werbekonto bei Meta?',
        antwort:
            'Ja — und es bleibt Ihres. Sie verbinden Ihr Werbekonto mit Mrs. Beauty; Kampagnen, Budget und Pixel gehören weiter Ihrer Praxis. Bei der Einrichtung, auch bei der Verifizierung bei Meta, begleiten wir Sie.',
    },
    {
        frage: 'Welche Kalender lassen sich verbinden?',
        antwort:
            'Der Google Kalender, mit Abgleich in beide Richtungen. Belegte Zeiten werden bei der Buchung berücksichtigt; nach außen steht nur „Beratung“, nie ein Name oder eine Behandlung.',
    },
    {
        frage: 'Wie lange sind wir gebunden?',
        antwort: `Das Abo läuft monatlich und ist monatlich kündbar. ${props.preise ? `Vorher testen Sie ${props.preise.testphaseTage} Tage` : 'Vorher testen Sie'} — mit Ihrem Team und echten Anfragen.`,
    },
]);
</script>

<template>
    <Head title="Werbung, Kommunikation und Termine für ästhetische Praxen" />

    <OeffentlichLayout startseite :firma="anbieter.firma">
        <!-- Hero ---------------------------------------------------------- -->
        <section class="relative isolate overflow-hidden" aria-labelledby="hero-titel">
            <div class="absolute inset-x-0 top-0 -z-10 h-[36rem] bg-gradient-to-b from-accent/70 to-transparent" aria-hidden="true" />

            <div
                class="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 pb-16 pt-12 sm:px-6 sm:pt-16 lg:grid-cols-[1.05fr_1fr] lg:gap-8 lg:pb-24 lg:pt-20"
            >
                <div class="space-y-7">
                    <Badge variant="default" class="gap-1.5 px-3 py-1 text-sm">
                        <Sparkles class="!size-3.5" />
                        Für Praxen für ästhetische Behandlungen
                    </Badge>

                    <h1 id="hero-titel" class="text-balance text-4xl font-semibold tracking-tight sm:text-5xl lg:text-[3.4rem] lg:leading-[1.08]">
                        Von der Anzeige bis zum Termin — <span class="text-primary">in einem System.</span>
                    </h1>

                    <p class="max-w-xl text-pretty text-lg text-muted-foreground">
                        Mrs. Beauty verbindet Ihre Meta-Werbung, WhatsApp und E-Mail, die Online-Buchung und die Warteliste. Ein KI-Assistent
                        beantwortet organisatorische Fragen, jede Anzeige läuft vorher durch die HWG-Prüfhilfe — und die Auswertung zeigt, welche
                        Kampagne Termine bringt.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button size="lg" as-child>
                            <a href="#demo">
                                <CalendarCheck />
                                Demo anfragen
                            </a>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <a href="#preise">
                                <Euro />
                                Preise ansehen
                            </a>
                        </Button>
                    </div>

                    <ul class="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:flex-wrap sm:gap-x-6">
                        <li v-for="punkt in [testphase, 'Monatlich kündbar', 'Einrichtung mit uns']" :key="punkt" class="flex items-center gap-2">
                            <Check class="size-4 text-primary" />
                            {{ punkt }}
                        </li>
                    </ul>
                </div>

                <HeroCollage />
            </div>
        </section>

        <!-- Heute an vier Stellen ------------------------------------------ -->
        <section class="border-y bg-muted/40" aria-labelledby="heute-titel">
            <div class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                <Abschnittskopf
                    kennung="heute-titel"
                    dachzeile="Das Problem"
                    titel="Heute an vier Stellen. Mit Mrs. Beauty an einer."
                    text="Werbung, Buchung und Empfangszeit laufen oft getrennt — und am Ende weiß niemand, welcher Euro welchen Termin gebracht hat."
                    mittig
                />

                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="punkt in heute" :key="punkt.titel" class="rounded-2xl border bg-card p-5">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                            <component :is="punkt.icon" class="size-5" />
                        </span>
                        <p class="mt-4 font-semibold">{{ punkt.titel }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ punkt.text }}</p>
                    </div>
                </div>

                <div class="mx-auto mt-6 flex max-w-3xl items-center gap-4 rounded-2xl bg-primary p-5 text-primary-foreground shadow-lg sm:p-6">
                    <span class="hidden size-11 shrink-0 items-center justify-center rounded-xl bg-primary-foreground/15 sm:flex">
                        <ArrowRight class="size-5" />
                    </span>
                    <p class="text-pretty">
                        <span class="font-semibold">Mrs. Beauty:</span> Werbung, Posteingang, Buchung und Auswertung in einem System — mit einer
                        durchgehenden Zahl von der Anzeige bis zum Termin.
                    </p>
                </div>
            </div>
        </section>

        <!-- Die Kette ------------------------------------------------------ -->
        <section class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-24" aria-labelledby="kette-titel">
            <Abschnittskopf
                kennung="kette-titel"
                dachzeile="Die Kette"
                titel="Sie sehen, was eine Anzeige wirklich bringt."
                text="Nicht Klicks, sondern Anfragen, Termine und erschienene Patientinnen — je Kampagne, jede Stufe mit ihrer Kennzahl."
                mittig
            />
            <div class="mt-12">
                <KettenGrafik />
            </div>
        </section>

        <!-- Funktionen ----------------------------------------------------- -->
        <section id="funktionen" class="scroll-mt-20 bg-muted/40 py-16 lg:py-24" aria-labelledby="funktionen-titel">
            <div class="mx-auto w-full max-w-6xl space-y-20 px-4 sm:px-6 lg:space-y-28">
                <Abschnittskopf
                    kennung="funktionen-titel"
                    dachzeile="Funktionen"
                    titel="Alles, was zwischen Anzeige und Termin passiert."
                    text="Vier Bereiche, ein System — ohne Export dazwischen."
                    mittig
                />

                <Funktionszeile
                    :icon="Bot"
                    dachzeile="KI-Empfang und Posteingang"
                    titel="Jede Anfrage beantwortet — und die heiklen nicht von der KI."
                    text="Nachrichten per WhatsApp und E-Mail landen in einem Posteingang. Der Assistent beantwortet organisatorische Fragen und Preise aus Ihrem Katalog, bietet freie Termine an und bucht nach Rückfrage. Medizinische Fragen gibt er ohne eigene Antwort an Ihr Team."
                    :punkte="[
                        'Startet im Freigabemodus: abgeschickt wird, was Sie freigeben',
                        'Übergabe an einen Menschen bei medizinischen Fragen, Beschwerden und Fotos',
                        'Jede Nachricht des Assistenten ist als KI-Nachricht gekennzeichnet',
                    ]"
                >
                    <EmpfangGrafik />
                </Funktionszeile>

                <Funktionszeile
                    :icon="CalendarCheck"
                    dachzeile="Buchung, Kalender und Warteliste"
                    titel="Termine rund um die Uhr — ohne Doppelbuchung und ohne Rundruf."
                    text="Interessentinnen buchen auf Ihrer Buchungsseite im Design Ihrer Praxis. Belegte Zeiten aus dem Google Kalender fließen ein, nach außen steht nur „Beratung“. Wird ein Termin frei, bietet die Warteliste ihn gestaffelt an."
                    :punkte="[
                        'Buchungsseite mit Ihrem Logo und Ihrer Farbe',
                        'Abgleich mit dem Google Kalender in beide Richtungen',
                        'Bestätigung und Erinnerung per E-Mail vor dem Termin',
                    ]"
                    gespiegelt
                >
                    <KalenderGrafik />
                </Funktionszeile>

                <Funktionszeile
                    :icon="Megaphone"
                    dachzeile="Meta-Werbung mit HWG-Prüfhilfe"
                    titel="Anzeigen im Ton Ihrer Praxis — mit Prüfhilfe vor dem Veröffentlichen."
                    text="Verbinden Sie Ihr Werbekonto und schalten Sie Kampagnen direkt aus Mrs. Beauty. Jeden Montag liegen neue Anzeigenentwürfe aus Ihrem Brand Guide bereit. Vor jeder Übermittlung markiert die HWG-Prüfhilfe heikle Formulierungen, nennt die Fundstelle und schlägt eine Alternative vor."
                    :punkte="[
                        'Kampagnen anlegen, pausieren und Budget setzen',
                        'KI-Anzeigenbilder in 1:1, 4:5 und 9:16',
                        'Eine Prüfhilfe, keine Rechtsberatung — die Entscheidung bleibt bei Ihnen',
                    ]"
                >
                    <HwgGrafik />
                </Funktionszeile>

                <Funktionszeile
                    :icon="BarChart3"
                    dachzeile="Auswertung"
                    titel="Welche Kampagne bringt Termine? Jetzt wissen Sie es."
                    text="Kosten je Anfrage, Kosten je Beratung, Erscheinungsquote und ROAS — je Kampagne und aus Ihren eigenen Zahlen. Der Umsatz kommt aus Ihrem Leistungskatalog und steht als zugeordneter Schätzwert da, nicht als Versprechen."
                    :punkte="[
                        'Jede Anfrage zählt, von der ersten Nachricht bis zum Termin',
                        'Reaktionszeit Ihres Teams auf neue Anfragen',
                        'Erschienene Termine statt gebuchter Absichten',
                    ]"
                    gespiegelt
                >
                    <AuswertungGrafik />
                </Funktionszeile>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="punkt in weitere" :key="punkt.titel" class="rounded-2xl border bg-card p-5">
                        <component :is="punkt.icon" class="size-5 text-primary" />
                        <p class="mt-3 font-semibold">{{ punkt.titel }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ punkt.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Zwischenaufruf ------------------------------------------------- -->
        <section class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6" aria-labelledby="zwischen-titel">
            <div class="relative isolate overflow-hidden rounded-3xl bg-primary px-6 py-12 text-primary-foreground sm:px-12">
                <div class="absolute -right-24 -top-24 -z-10 size-80 rounded-full bg-primary-foreground/10 blur-2xl" aria-hidden="true" />
                <div class="absolute -bottom-32 left-1/3 -z-10 size-80 rounded-full bg-calendar-2/60 blur-3xl" aria-hidden="true" />

                <div class="grid items-center gap-8 lg:grid-cols-[1.5fr_1fr]">
                    <div class="space-y-3">
                        <h2 id="zwischen-titel" class="text-balance text-3xl font-semibold tracking-tight">Lieber zeigen als beschreiben.</h2>
                        <p class="max-w-xl text-pretty text-primary-foreground/80">
                            In der Demo gehen wir mit Ihnen den Weg einer Anfrage durch — von der Anzeige über den Posteingang bis zum erschienenen
                            Termin. Unverbindlich.
                        </p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:justify-end">
                        <Button size="lg" variant="secondary" as-child>
                            <a href="#demo">
                                <CalendarCheck />
                                Demo anfragen
                            </a>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Vertrauen ------------------------------------------------------ -->
        <section class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20" aria-labelledby="vertrauen-titel">
            <div class="grid gap-12 lg:grid-cols-[1fr_1.4fr] lg:gap-16">
                <Abschnittskopf
                    kennung="vertrauen-titel"
                    dachzeile="Datenschutz"
                    titel="Gebaut für eine Branche, in der schon ein Termin ein Gesundheitsdatum ist."
                    text="Wer sich in einer ästhetischen Praxis behandeln lässt, soll das nicht in einer Werbeplattform wiederfinden. Deshalb stehen diese Regeln nicht in einer Richtlinie, sondern im Code — und in Tests, die jede Änderung durchlaufen muss."
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="punkt in vertrauen" :key="punkt.titel" class="rounded-2xl border bg-card p-5">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                            <component :is="punkt.icon" class="size-5" />
                        </span>
                        <p class="mt-4 font-semibold">{{ punkt.titel }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ punkt.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Ablauf --------------------------------------------------------- -->
        <section id="ablauf" class="scroll-mt-20 border-y bg-muted/40" aria-labelledby="ablauf-titel">
            <div class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                <Abschnittskopf
                    kennung="ablauf-titel"
                    dachzeile="So funktioniert’s"
                    titel="In vier Schritten startklar."
                    text="Die Einrichtung ist echte Arbeit — deshalb machen wir sie mit Ihnen, nicht Sie allein."
                    mittig
                />

                <ol class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(schritt, index) in schritte" :key="schritt.titel" class="relative rounded-2xl border bg-card p-6">
                        <span class="flex size-10 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                            {{ index + 1 }}
                        </span>
                        <p class="mt-4 font-semibold">{{ schritt.titel }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ schritt.text }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Preise --------------------------------------------------------- -->
        <section id="preise" class="mx-auto w-full max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24" aria-labelledby="preise-titel">
            <Abschnittskopf
                kennung="preise-titel"
                dachzeile="Preise"
                titel="Ein Paket. Alle Funktionen."
                text="Keine Stufen, keine Zusatzmodule. Wer mehr Nachrichten oder Läufe braucht, stockt auf."
                mittig
            />
            <div class="mx-auto mt-12 max-w-5xl">
                <Preiskarte :preise="preise" />
            </div>
        </section>

        <!-- FAQ ------------------------------------------------------------ -->
        <section id="faq" class="scroll-mt-20 bg-muted/40" aria-labelledby="faq-titel">
            <div class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.6fr] lg:py-24">
                <Abschnittskopf
                    kennung="faq-titel"
                    dachzeile="FAQ"
                    titel="Häufige Fragen"
                    text="Ihre Frage ist nicht dabei? Stellen Sie sie in der Demo."
                >
                    <Button variant="outline" class="mt-2" as-child>
                        <a href="#demo">
                            <CalendarCheck />
                            Demo anfragen
                        </a>
                    </Button>
                </Abschnittskopf>
                <Fragen :fragen="fragen" />
            </div>
        </section>

        <!-- Demo ----------------------------------------------------------- -->
        <section id="demo" class="relative isolate scroll-mt-20 overflow-hidden" aria-labelledby="demo-titel">
            <div class="absolute inset-0 -z-10 bg-gradient-to-b from-background via-accent/60 to-background" aria-hidden="true" />

            <div class="mx-auto grid w-full max-w-6xl items-start gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div class="space-y-8">
                    <Abschnittskopf
                        kennung="demo-titel"
                        dachzeile="Demo anfragen"
                        titel="Sehen Sie Mrs. Beauty an Ihrem Beispiel."
                        text="Hinterlassen Sie uns Ihre Kontaktdaten — wir melden uns und vereinbaren einen Termin."
                    />

                    <ul class="space-y-4">
                        <li
                            v-for="punkt in [
                                'Der Weg einer Anfrage, von der Anzeige bis zum erschienenen Termin',
                                'Ihre Fragen zu Datenschutz, KI-Assistent und Heilmittelwerbegesetz',
                                'Was die Einrichtung für Ihre Praxis bedeutet — und was sie kostet',
                            ]"
                            :key="punkt"
                            class="flex items-start gap-3"
                        >
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <Check class="size-3.5" />
                            </span>
                            {{ punkt }}
                        </li>
                    </ul>

                    <div class="space-y-2 rounded-2xl border bg-card/70 p-5 text-sm">
                        <p class="font-medium">Lieber direkt?</p>
                        <p class="flex items-center gap-2 text-muted-foreground">
                            <Mail class="size-4 text-primary" />
                            <a :href="`mailto:${anbieter.email}`" class="hover:text-foreground">{{ anbieter.email }}</a>
                        </p>
                        <p class="flex items-center gap-2 text-muted-foreground">
                            <Phone class="size-4 text-primary" />
                            <a :href="`tel:${anbieter.telefon.replace(/\s+/g, '')}`" class="hover:text-foreground">{{ anbieter.telefon }}</a>
                        </p>
                    </div>
                </div>

                <Demoformular :merkmal="demoformular.merkmal" />
            </div>
        </section>
    </OeffentlichLayout>
</template>
