<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarCheck, LayoutDashboard, LogIn, Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Das Gerüst der öffentlichen Seiten des Betreibers (WP-38): Startseite,
 * Impressum, Datenschutzerklärung.
 *
 * **Unsere Marke, nicht die einer Praxis** — anders als die Buchungsseite.
 * Die Kopfzeile trägt die Anker der Startseite, „Anmelden“ für das Team einer
 * Praxis und die Demo-Anfrage als Hauptaktion. Der Eingang der Betreiber
 * steht hier nicht: er hat eine eigene Adresse, und wer ihn braucht, kennt sie.
 *
 * Auf den Rechtstexten führen die Anker zurück auf die Startseite.
 */
const props = withDefaults(defineProps<{ startseite?: boolean; firma: string }>(), { startseite: false });

const page = usePage<SharedData>();

const angemeldet = computed(() => page.props.auth?.user != null);

const anker = (ziel: string): string => (props.startseite ? `#${ziel}` : `${route('home')}#${ziel}`);

const navigation = computed(() => [
    { titel: 'Funktionen', href: anker('funktionen') },
    { titel: 'So funktioniert’s', href: anker('ablauf') },
    { titel: 'Preise', href: anker('preise') },
    { titel: 'FAQ', href: anker('faq') },
]);

const menueOffen = ref(false);

const jahr = new Date().getFullYear();
</script>

<template>
    <div data-oeffentlich class="flex min-h-svh flex-col bg-background text-foreground">
        <a
            href="#inhalt"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:shadow"
        >
            Zum Inhalt springen
        </a>

        <header class="sticky top-0 z-50 border-b bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/70">
            <div class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link :href="route('home')" class="flex items-center gap-2.5 font-semibold" aria-label="Mrs. Beauty — zur Startseite">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span class="text-base tracking-tight">Mrs. Beauty</span>
                </Link>

                <nav aria-label="Hauptnavigation" class="hidden items-center gap-1 md:flex">
                    <a
                        v-for="punkt in navigation"
                        :key="punkt.titel"
                        :href="punkt.href"
                        class="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        {{ punkt.titel }}
                    </a>
                </nav>

                <div class="flex items-center gap-2">
                    <!-- Für das Team einer Praxis — der Eingang der Betreiber hat eine eigene Adresse. -->
                    <Button v-if="angemeldet" variant="ghost" class="hidden sm:inline-flex" as-child>
                        <Link :href="route('dashboard')">
                            <LayoutDashboard />
                            Zum Dashboard
                        </Link>
                    </Button>
                    <Button v-else variant="ghost" class="hidden sm:inline-flex" as-child>
                        <Link :href="route('login')">
                            <LogIn />
                            Anmelden
                        </Link>
                    </Button>

                    <!-- Die Hauptaktion steht auch auf dem Handy in der Zeile, nur kürzer. -->
                    <Button as-child>
                        <a :href="anker('demo')">
                            <CalendarCheck />
                            <span>Demo<span class="hidden min-[400px]:inline">&nbsp;anfragen</span></span>
                        </a>
                    </Button>

                    <Sheet v-model:open="menueOffen">
                        <SheetTrigger as-child>
                            <Button variant="ghost" size="icon" class="md:hidden" aria-label="Menü öffnen">
                                <Menu />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="right" class="flex flex-col gap-6">
                            <SheetHeader class="text-left">
                                <SheetTitle>Mrs. Beauty</SheetTitle>
                                <SheetDescription>Werbung, Kommunikation und Termine für ästhetische Praxen.</SheetDescription>
                            </SheetHeader>

                            <nav aria-label="Mobile Navigation" class="grid gap-1">
                                <a
                                    v-for="punkt in navigation"
                                    :key="punkt.titel"
                                    :href="punkt.href"
                                    class="rounded-md px-3 py-2.5 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                                    @click="menueOffen = false"
                                >
                                    {{ punkt.titel }}
                                </a>
                            </nav>

                            <Separator />

                            <div class="grid gap-2">
                                <Button as-child>
                                    <a :href="anker('demo')" @click="menueOffen = false">
                                        <CalendarCheck />
                                        Demo anfragen
                                    </a>
                                </Button>
                                <Button v-if="angemeldet" variant="outline" as-child>
                                    <Link :href="route('dashboard')">
                                        <LayoutDashboard />
                                        Zum Dashboard
                                    </Link>
                                </Button>
                                <Button v-else variant="outline" as-child>
                                    <Link :href="route('login')">
                                        <LogIn />
                                        Anmelden
                                    </Link>
                                </Button>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>

        <main id="inhalt" class="flex-1">
            <slot />
        </main>

        <footer class="border-t bg-muted/40">
            <div class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[2fr_1fr_1fr_1fr]">
                <div class="space-y-3">
                    <Link :href="route('home')" class="flex items-center gap-2.5 font-semibold">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                            <AppLogoIcon class="size-5" />
                        </span>
                        <span>Mrs. Beauty</span>
                    </Link>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        Werbung, Kommunikation und Termine für Praxen für ästhetische Behandlungen — an einer Stelle.
                    </p>
                </div>

                <nav aria-label="Produkt" class="space-y-3 text-sm">
                    <p class="font-medium">Produkt</p>
                    <ul class="space-y-2 text-muted-foreground">
                        <li><a :href="anker('funktionen')" class="hover:text-foreground">Funktionen</a></li>
                        <li><a :href="anker('preise')" class="hover:text-foreground">Preise</a></li>
                        <li><a :href="anker('faq')" class="hover:text-foreground">FAQ</a></li>
                        <li><a :href="anker('demo')" class="hover:text-foreground">Demo anfragen</a></li>
                    </ul>
                </nav>

                <nav aria-label="Zugang" class="space-y-3 text-sm">
                    <p class="font-medium">Zugang</p>
                    <ul class="space-y-2 text-muted-foreground">
                        <li v-if="angemeldet"><Link :href="route('dashboard')" class="hover:text-foreground">Zum Dashboard</Link></li>
                        <li v-else><Link :href="route('login')" class="hover:text-foreground">Anmelden</Link></li>
                    </ul>
                </nav>

                <nav aria-label="Rechtliches" class="space-y-3 text-sm">
                    <p class="font-medium">Rechtliches</p>
                    <ul class="space-y-2 text-muted-foreground">
                        <li><Link :href="route('impressum')" class="hover:text-foreground">Impressum</Link></li>
                        <li><Link :href="route('datenschutzerklaerung')" class="hover:text-foreground">Datenschutzerklärung</Link></li>
                    </ul>
                </nav>
            </div>

            <div class="border-t">
                <p class="mx-auto w-full max-w-6xl px-4 py-5 text-xs text-muted-foreground sm:px-6">
                    © {{ jahr }} {{ firma }}. Ohne Tracking, ohne Cookie-Banner.
                </p>
            </div>
        </footer>
    </div>
</template>
