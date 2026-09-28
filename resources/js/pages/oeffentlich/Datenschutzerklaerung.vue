<script setup lang="ts">
import { type Anbieter } from '@/components/oeffentlich/typen';
import OeffentlichLayout from '@/layouts/oeffentlich/OeffentlichLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

/**
 * Die Datenschutzerklärung der öffentlichen Seiten (WP-38).
 *
 * Aufbau und Wortlaut folgen upscale-it.de/datenschutz (28.09.2026). **Was
 * diese Seiten nicht einsetzen, steht hier nicht:** kein Newsletter, keine
 * Videos, kein Meta-Pixel, keine Webanalyse, kein Consent-Banner, keine
 * Google-Schriften, kein reCAPTCHA. Dazu gekommen sind die Demo-Anfrage und
 * die Schrift von Bunny Fonts.
 *
 * Cookies, Drittanbieter und die Frist kommen als Props aus derselben
 * Konfiguration, gegen die tests/Feature/Oeffentlich/OhneTrackingTest.php die
 * Seiten prüft — die Erklärung kann der Seite nicht davonlaufen.
 *
 * Sie gilt für die Website, nicht für das Produkt: Was eine Praxis in
 * Mrs. Beauty verarbeitet, regelt der Vertrag zur Auftragsverarbeitung.
 */
defineProps<{
    anbieter: Anbieter;
    cookies: { name: string; zweck: string; dauer: string }[];
    drittanbieter: { host: string; anbieter: string; zweck: string }[];
    aufbewahrungMonate: number;
}>();
</script>

<template>
    <Head title="Datenschutzerklärung" />

    <OeffentlichLayout :firma="anbieter.firma">
        <article class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Datenschutzerklärung</h1>
            <p class="mt-2 text-muted-foreground">Für diese Website — die Startseite, die Demo-Anfrage und die Rechtstexte.</p>

            <div class="mt-8 rounded-2xl border bg-accent p-5 text-sm text-accent-foreground">
                <p class="font-semibold">Kurz gesagt</p>
                <p class="mt-1">
                    Diese Seiten setzen kein Tracking ein: keine Webanalyse, kein Werbe-Pixel, keine Einbindungen sozialer Netzwerke. Es gibt nur die
                    Cookies, die für den Betrieb nötig sind — deshalb auch kein Cookie-Banner. Was Sie uns über die Demo-Anfrage schicken, liegt
                    verschlüsselt bei uns und wird nach {{ aufbewahrungMonate }} Monaten gelöscht.
                </p>
            </div>

            <div class="mt-10 space-y-10 text-[0.95rem] leading-relaxed">
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">1. Verantwortlicher</h2>
                    <p>Verantwortlich für die Verarbeitung personenbezogener Daten auf dieser Website ist:</p>
                    <address class="not-italic">
                        {{ anbieter.firma }}<br />
                        {{ anbieter.strasse }}<br />
                        {{ anbieter.plz }} {{ anbieter.ort }}, {{ anbieter.land }}<br />
                        Telefon: {{ anbieter.telefon }}<br />
                        E-Mail: <a :href="`mailto:${anbieter.email}`" class="text-primary underline-offset-2 hover:underline">{{ anbieter.email }}</a>
                    </address>
                    <p>
                        Zum Schutz der übertragenen Daten nutzt diese Website eine SSL- bzw. TLS-Verschlüsselung. Eine verschlüsselte Verbindung
                        erkennen Sie an „https://“ in der Adresszeile Ihres Browsers.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">2. Besuch der Website</h2>
                    <p>
                        Bei der rein informatorischen Nutzung dieser Website erhebt unser Server die Daten, die Ihr Browser übermittelt und die
                        technisch erforderlich sind, um Ihnen die Seite anzuzeigen und Stabilität und Sicherheit zu gewährleisten:
                    </p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>die aufgerufene Seite, Datum und Uhrzeit des Zugriffs,</li>
                        <li>die übertragene Datenmenge und der Status der Antwort,</li>
                        <li>die Website, von der Sie kommen (Referrer),</li>
                        <li>Browser und Betriebssystem,</li>
                        <li>Ihre IP-Adresse.</li>
                    </ul>
                    <p>
                        Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO; unser berechtigtes Interesse liegt im sicheren und stabilen Betrieb der
                        Website. Diese Daten werden nicht mit anderen Datenquellen zusammengeführt und nicht für Werbung verwendet.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">3. Cookies</h2>
                    <p>
                        Diese Website setzt ausschließlich technisch notwendige Cookies. Sie werden nicht für Analyse oder Werbung verwendet und nicht
                        an Dritte weitergegeben:
                    </p>
                    <div class="overflow-x-auto rounded-xl border">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-muted/60 text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2 font-medium">Name</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Zweck</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Dauer</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="cookie in cookies" :key="cookie.name">
                                    <td class="px-4 py-2 font-mono text-xs">{{ cookie.name }}</td>
                                    <td class="px-4 py-2">{{ cookie.zweck }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ cookie.dauer }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p>
                        Rechtsgrundlage ist § 25 Abs. 2 Nr. 2 TDDDG in Verbindung mit Art. 6 Abs. 1 lit. f DSGVO. Eine Einwilligung ist für diese
                        Cookies nicht erforderlich. Sie können Cookies in Ihrem Browser jederzeit löschen oder blockieren; das Formular funktioniert
                        dann jedoch nicht mehr.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">4. Schriftarten</h2>
                    <p>
                        Für eine einheitliche Darstellung lädt diese Website Schriftarten von folgendem Anbieter. Dabei überträgt Ihr Browser Ihre
                        IP-Adresse an dessen Server:
                    </p>
                    <ul class="space-y-2">
                        <li v-for="eintrag in drittanbieter" :key="eintrag.host" class="rounded-xl border p-4">
                            <p class="font-medium">{{ eintrag.host }}</p>
                            <p class="text-sm text-muted-foreground">{{ eintrag.anbieter }}</p>
                            <p class="text-sm">{{ eintrag.zweck }}</p>
                        </li>
                    </ul>
                    <p>
                        Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO; unser berechtigtes Interesse liegt in einer einheitlichen und schnellen
                        Darstellung der Website. Andere Dienste Dritter lädt diese Website nicht.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">5. Demo-Anfrage</h2>
                    <p>
                        Wenn Sie über das Formular eine Demo anfragen, verarbeiten wir Ihren Namen, den Namen Ihrer Praxis und Ihre E-Mail-Adresse,
                        auf Wunsch auch Telefonnummer und Ort. Das Formular hat bewusst kein Freitextfeld. Wir verwenden die Angaben ausschließlich,
                        um Sie wegen der Demo zu kontaktieren und einen Termin zu vereinbaren.
                    </p>
                    <p>
                        Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Durchführung vorvertraglicher Maßnahmen auf Ihre Anfrage), ergänzend Art. 6
                        Abs. 1 lit. f DSGVO (unser berechtigtes Interesse, Anfragen zu beantworten).
                    </p>
                    <p>
                        Die Angaben liegen verschlüsselt in unserem System. Über den Eingang benachrichtigen wir unser Vertriebsteam per E-Mail — ohne
                        Ihre Angaben: Die Nachricht nennt nur den Zeitpunkt. Zum Schutz vor automatisierten Eingaben prüfen wir, wie schnell das
                        Formular abgeschickt wurde; dafür setzen wir keinen Dienst Dritter ein.
                    </p>
                    <p>
                        Wir löschen die Angaben nach {{ aufbewahrungMonate }} Monaten automatisch, früher, wenn Sie es verlangen — es sei denn, aus
                        der Anfrage ist ein Vertrag entstanden, dann gelten die gesetzlichen Aufbewahrungspflichten.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">6. Kontakt per E-Mail oder Telefon</h2>
                    <p>
                        Schreiben Sie uns eine E-Mail oder rufen Sie uns an, verarbeiten wir Ihre Angaben ausschließlich zur Beantwortung Ihres
                        Anliegens. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO, bei einer Anfrage zu einem Vertrag Art. 6 Abs. 1 lit. b DSGVO. Nach
                        Erledigung löschen wir die Angaben, sofern keine gesetzlichen Aufbewahrungspflichten bestehen.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">7. Empfänger</h2>
                    <p>
                        Für Hosting, E-Mail-Versand und die Überwachung technischer Fehler setzen wir Dienstleister ein, die Daten ausschließlich in
                        unserem Auftrag und nach unserer Weisung verarbeiten (Art. 28 DSGVO). Darüber hinaus geben wir Ihre Daten nicht weiter, es sei
                        denn, wir sind gesetzlich dazu verpflichtet.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">8. Kein Tracking</h2>
                    <p>
                        Wir setzen auf diesen Seiten keine Webanalyse, kein Meta-Pixel oder anderes Werbe-Tracking, keine eingebetteten Videos und
                        keine Plugins sozialer Netzwerke ein. Es findet keine Profilbildung statt.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">9. Ihre Rechte</h2>
                    <p>Sie haben gegenüber uns folgende Rechte hinsichtlich der Sie betreffenden personenbezogenen Daten:</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Recht auf Auskunft (Art. 15 DSGVO)</li>
                        <li>Recht auf Berichtigung (Art. 16 DSGVO)</li>
                        <li>Recht auf Löschung (Art. 17 DSGVO)</li>
                        <li>Recht auf Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
                        <li>Recht auf Unterrichtung (Art. 19 DSGVO)</li>
                        <li>Recht auf Datenübertragbarkeit (Art. 20 DSGVO)</li>
                        <li>Recht auf Widerruf einer Einwilligung mit Wirkung für die Zukunft (Art. 7 Abs. 3 DSGVO)</li>
                    </ul>
                    <p>
                        Sie haben außerdem das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren (Art. 77 DSGVO). Für uns zuständig ist
                        der Hamburgische Beauftragte für Datenschutz und Informationsfreiheit.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">10. Widerspruchsrecht</h2>
                    <p>
                        Verarbeiten wir Ihre Daten auf Grundlage berechtigter Interessen (Art. 6 Abs. 1 lit. f DSGVO), können Sie dieser Verarbeitung
                        aus Gründen, die sich aus Ihrer besonderen Situation ergeben, jederzeit widersprechen (Art. 21 DSGVO). Wir verarbeiten die
                        Daten dann nicht mehr, es sei denn, wir können zwingende schutzwürdige Gründe nachweisen, die Ihre Interessen überwiegen, oder
                        die Verarbeitung dient der Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen.
                    </p>
                    <p>
                        Eine formlose Nachricht an
                        <a :href="`mailto:${anbieter.email}`" class="text-primary underline-offset-2 hover:underline">{{ anbieter.email }}</a>
                        genügt.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold">11. Dauer der Speicherung</h2>
                    <p>
                        Wir speichern personenbezogene Daten nur so lange, wie es für den jeweiligen Zweck erforderlich ist. Beruht die Verarbeitung
                        auf einer Einwilligung, speichern wir bis zu deren Widerruf; beruht sie auf einem berechtigten Interesse, bis zu Ihrem
                        Widerspruch, sofern keine zwingenden Gründe entgegenstehen. Gesetzliche Aufbewahrungspflichten bleiben unberührt.
                    </p>
                </section>

                <p class="border-t pt-6 text-sm text-muted-foreground">
                    Stand: September 2026. Die Angaben zum Anbieter finden Sie im
                    <Link :href="route('impressum')" class="text-primary underline-offset-2 hover:underline">Impressum</Link>.
                </p>
            </div>
        </article>
    </OeffentlichLayout>
</template>
