import { cva, type VariantProps } from 'class-variance-authority';

export { default as Badge } from './Badge.vue';

/**
 * Farbe trägt nie allein Bedeutung (docs/design/farben.md). Jedes Abzeichen,
 * das einen Zustand meldet, bekommt zusätzlich ein Symbol — die Variante
 * liefert nur den Ton.
 */
export const badgeVariants = cva(
    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition-colors [&_svg]:size-3 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary/10 text-primary',
                secondary: 'border-transparent bg-muted text-muted-foreground',
                outline: 'text-foreground',
                success: 'border-transparent bg-success/10 text-success',
                warning: 'border-transparent bg-warning/10 text-warning',
                destructive: 'border-transparent bg-destructive/10 text-destructive',
                info: 'border-transparent bg-info/10 text-info',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type BadgeVariants = VariantProps<typeof badgeVariants>;
