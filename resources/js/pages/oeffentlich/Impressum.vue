<script setup lang="ts">
import { type Anbieter } from '@/components/oeffentlich/typen';
import OeffentlichLayout from '@/layouts/oeffentlich/OeffentlichLayout.vue';
import { Head } from '@inertiajs/vue3';

/**
 * Das Impressum des Betreibers (WP-38), nach § 5 DDG.
 *
 * Übernommen von upscale-it.de/impressum (28.09.2026), mit zwei bewussten
 * Abweichungen: Das Telemediengesetz ist durch das Digitale-Dienste-Gesetz
 * ersetzt, und die OS-Plattform der EU-Kommission ist seit dem 20.07.2025
 * abgeschaltet — ein Link dorthin wäre ein toter Link in einem Pflichttext.
 *
 * Die Angaben kommen aus `mrs.oeffentlich.anbieter`, nicht aus dieser Datei.
 * Als Auszeichnung gesetzt, nie über `v-html` (Regel 5).
 */
defineProps<{ anbieter: Anbieter }>();
</script>

<template>
    <Head title="Impressum" />

    <OeffentlichLayout :firma="anbieter.firma">
        <article class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Impressum</h1>
            <p class="mt-2 text-muted-foreground">Angaben gemäß § 5 Digitale-Dienste-Gesetz (DDG)</p>

            <div class="mt-10 space-y-10 text-[0.95rem] leading-relaxed">
                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Anbieter</h2>
                    <address class="not-italic">
                        {{ anbieter.firma }}<br />
                        {{ anbieter.strasse }}<br />
                        {{ anbieter.plz }} {{ anbieter.ort }}<br />
                        {{ anbieter.land }}
                    </address>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Kontakt</h2>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1">
                        <dt class="text-muted-foreground">E-Mail</dt>
                        <dd>
                            <a :href="`mailto:${anbieter.email}`" class="text-primary underline-offset-2 hover:underline">{{ anbieter.email }}</a>
                        </dd>
                        <dt class="text-muted-foreground">Telefon</dt>
                        <dd>
                            <a :href="`tel:${anbieter.telefon.replace(/\s+/g, '')}`" class="text-primary underline-offset-2 hover:underline">{{
                                anbieter.telefon
                            }}</a>
                        </dd>
                    </dl>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Vertreten durch</h2>
                    <p>Geschäftsführer: {{ anbieter.vertreten_durch }}</p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Registereintrag</h2>
                    <p>
                        Eintragung im Handelsregister<br />
                        Registergericht: {{ anbieter.registergericht }}<br />
                        Registernummer: {{ anbieter.registernummer }}
                    </p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Umsatzsteuer-ID</h2>
                    <p>Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz: {{ anbieter.ust_id }}</p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Verbraucherstreitbeilegung</h2>
                    <p>
                        Wir sind weder verpflichtet noch grundsätzlich bereit, an einem Streitbeilegungsverfahren vor einer
                        Verbraucherschlichtungsstelle teilzunehmen.
                    </p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Haftung für Inhalte</h2>
                    <p>
                        Die Inhalte unserer Seiten wurden mit größter Sorgfalt erstellt. Für die Richtigkeit, Vollständigkeit und Aktualität der
                        Inhalte können wir jedoch keine Gewähr übernehmen. Als Diensteanbieter sind wir gemäß § 7 Abs. 1 DDG für eigene Inhalte auf
                        diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 DDG sind wir als Diensteanbieter jedoch nicht
                        verpflichtet, übermittelte oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu forschen, die auf eine
                        rechtswidrige Tätigkeit hinweisen.
                    </p>
                    <p>
                        Verpflichtungen zur Entfernung oder Sperrung der Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon
                        unberührt. Eine diesbezügliche Haftung ist jedoch erst ab dem Zeitpunkt der Kenntnis einer konkreten Rechtsverletzung möglich.
                        Bei Bekanntwerden von entsprechenden Rechtsverletzungen werden wir diese Inhalte umgehend entfernen.
                    </p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Haftung für Links</h2>
                    <p>
                        Unser Angebot enthält Links zu externen Websites Dritter, auf deren Inhalte wir keinen Einfluss haben. Deshalb können wir für
                        diese fremden Inhalte auch keine Gewähr übernehmen. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter
                        oder Betreiber der Seiten verantwortlich. Die verlinkten Seiten wurden zum Zeitpunkt der Verlinkung auf mögliche
                        Rechtsverstöße überprüft; rechtswidrige Inhalte waren zu diesem Zeitpunkt nicht erkennbar.
                    </p>
                    <p>
                        Eine permanente inhaltliche Kontrolle der verlinkten Seiten ist jedoch ohne konkrete Anhaltspunkte einer Rechtsverletzung
                        nicht zumutbar. Bei Bekanntwerden von Rechtsverletzungen werden wir derartige Links umgehend entfernen.
                    </p>
                </section>

                <section class="space-y-2">
                    <h2 class="text-lg font-semibold">Urheberrecht</h2>
                    <p>
                        Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Die
                        Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der
                        schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers.
                    </p>
                </section>
            </div>
        </article>
    </OeffentlichLayout>
</template>
