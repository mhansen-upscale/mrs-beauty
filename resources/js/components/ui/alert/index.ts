import { cva, type VariantProps } from 'class-variance-authority';

export { default as Alert } from './Alert.vue';
export { default as AlertDescription } from './AlertDescription.vue';
export { default as AlertTitle } from './AlertTitle.vue';

/**
 * Übernommen aus shadcn-vue (Fassung für radix-vue), um die Semantikfarben
 * ergänzt wie `ui/badge`.
 *
 * **Jeder Hinweis trägt ein Symbol** — Farbe trägt nie allein Bedeutung
 * (docs/design/farben.md). Das Symbol steht als erstes Kind und wird von
 * hier aus links gesetzt; der Text rückt daneben ein. Durchgesetzt von
 * tests/Feature/Design/BauteileTest.php.
 *
 * Vorher gab es rund vierzig Hinweiskästen von Hand, in drei Farbstärken,
 * drei Innenabständen und mal mit, mal ohne Symbol.
 */
export const alertVariants = cva(
    'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:size-4 [&>svg~*]:pl-7',
    {
        variants: {
            variant: {
                default: 'bg-muted/40 text-muted-foreground [&>svg]:text-muted-foreground',
                destructive: 'border-destructive/40 bg-destructive/5 text-destructive [&>svg]:text-destructive',
                warning: 'border-warning/40 bg-warning/5 text-warning [&>svg]:text-warning',
                success: 'border-success/40 bg-success/5 text-success [&>svg]:text-success',
                info: 'border-info/40 bg-info/5 text-info [&>svg]:text-info',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type AlertVariants = VariantProps<typeof alertVariants>;
