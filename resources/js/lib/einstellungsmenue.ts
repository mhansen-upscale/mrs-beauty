import type { NavItem } from '@/types';
import { Bot, ChartNoAxesCombined, CreditCard, KeyRound, Mail, Mails, MessageCircle, Palette, ShieldCheck, User } from 'lucide-vue-next';

/**
 * Die Navigation der Einstellungen: das eigene Konto — und die wenigen
 * Einstellungen, die die Praxis betreffen, ohne Arbeitsbereich zu sein.
 * Stammdaten, Katalog, Team und Protokoll stehen in der Hauptnavigation.
 *
 * **Eine Liste, zwei Leser.** Das Layout der Einstellungen zeigt sie, und der
 * letzte Schritt der Einführung zählt sie auf. Stünde sie zweimal, sagte die
 * Führung irgendwann etwas anderes als das Menü (`BauteileTest`).
 *
 * Ausgeblendet ist nicht geschützt — die Tür sind die Gates in
 * `routes/settings.php`.
 */
export const einstellungsmenue = (darf: (ability: string) => boolean): NavItem[] => [
    { title: 'Profil', href: '/settings/profile', icon: User },
    { title: 'Passwort', href: '/settings/password', icon: KeyRound },
    { title: 'Zweiter Faktor', href: '/settings/zwei-faktor', icon: ShieldCheck },
    ...(darf('organization.manage')
        ? [
              { title: 'Postfach', href: '/settings/postfach', icon: Mail },
              { title: 'WhatsApp', href: '/settings/whatsapp', icon: MessageCircle },
              { title: 'Tracking', href: '/settings/tracking', icon: ChartNoAxesCombined },
          ]
        : []),
    ...(darf('whitelabel.manage')
        ? [
              { title: 'Erscheinungsbild', href: '/settings/erscheinungsbild', icon: Palette },
              { title: 'E-Mails', href: '/settings/mails', icon: Mails },
          ]
        : []),
    ...(darf('agent.manage') ? [{ title: 'Assistent', href: '/settings/assistent', icon: Bot }] : []),
    ...(darf('billing.manage') ? [{ title: 'Abo', href: '/settings/abo', icon: CreditCard }] : []),
];

/** Was jede Person hat, gleich welche Rolle — das eigene Konto. */
export const kontoeinstellungen = ['/settings/profile', '/settings/password', '/settings/zwei-faktor'];
