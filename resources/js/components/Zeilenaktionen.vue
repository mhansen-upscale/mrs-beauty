<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { Zeilenaktion } from '@/types';
import { MoreHorizontal } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Aktionen einer Tabellenzeile, ab drei Stück.
 *
 * Ab `sm` stehen sie als Symbolknöpfe nebeneinander, darunter in einem Menü
 * „Weitere Aktionen". Vorher lief eine Zeile mit drei oder vier Knöpfen auf
 * dem Handy seitlich aus der Tabelle, und in Behandler waren Arbeitszeiten,
 * Bild und Abwesenheiten unter `sm`/`lg` einfach ausgeblendet — ohne einen
 * anderen Weg dorthin.
 *
 * Im Menü steht die Beschriftung sichtbar daneben: ein Tooltip braucht eine
 * Maus, und auf einem Berührungsbildschirm gibt es keine.
 */
const props = defineProps<{
    aktionen: Zeilenaktion[];
}>();

const sichtbar = computed(() => props.aktionen.filter((aktion) => aktion.wenn !== false));
</script>

<template>
    <div class="hidden items-center justify-end gap-1 sm:flex">
        <AktionsButton
            v-for="aktion in sichtbar"
            :key="aktion.beschriftung"
            :icon="aktion.symbol"
            :beschriftung="aktion.beschriftung"
            :disabled="aktion.gesperrt"
            :class="aktion.gefahr ? 'text-destructive hover:text-destructive' : ''"
            @click="aktion.aktion()"
        />
    </div>

    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button type="button" variant="ghost" size="icon" class="sm:hidden" aria-label="Weitere Aktionen">
                <MoreHorizontal />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="min-w-48">
            <DropdownMenuItem
                v-for="aktion in sichtbar"
                :key="aktion.beschriftung"
                :disabled="aktion.gesperrt"
                :class="aktion.gefahr ? 'text-destructive focus:text-destructive' : ''"
                @select="aktion.aktion()"
            >
                <component :is="aktion.symbol" />
                {{ aktion.beschriftung }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
