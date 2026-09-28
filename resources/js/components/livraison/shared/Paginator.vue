<!-- resources/js/components/livraison/shared/Paginator.vue -->
<!--
    Pagination pour les listes chargées en JSON par les écrans livraison
    (checklist familles éligibles, file de contact) — jusqu'ici la seule
    pagination de l'app est côté serveur/navigation classique (voir
    resources/views/partials/pagination.blade.php) ; celle-ci reprend
    volontairement le même visuel et la même règle "Précédent/Suivant +
    Page X / Y, pas de liste de numéros" plutôt qu'inventer un style de
    pagination différent pour ces listes chargées en JS.

    N'appelle pas fetch elle-même : reçoit `meta` (Paginated<T>['meta'])
    et émet @change(page) — le composant parent reste responsable du
    fetch, cette pagination est juste l'affichage + les boutons.
-->
<script setup lang="ts">
import { computed } from 'vue';
import type { Paginated } from './types';

const props = defineProps<{
    meta: Paginated<unknown>['meta'];
}>();

const emit = defineEmits<{
    change: [page: number];
}>();

const surPremierePage = computed(() => props.meta.current_page <= 1);
const surDernierePage = computed(() => props.meta.current_page >= props.meta.last_page);

function aller(page: number) {
    if (page < 1 || page > props.meta.last_page || page === props.meta.current_page) return;
    emit('change', page);
}
</script>

<template>
    <div v-if="meta.total > 0" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p class="text-[12px] text-ink-muted order-2 sm:order-1">
            Affichage <strong class="text-ink">{{ meta.from }}–{{ meta.to }}</strong>
            sur <strong class="text-ink">{{ meta.total }}</strong>
            {{ meta.total > 1 ? 'résultats' : 'résultat' }}
        </p>

        <div v-if="meta.last_page > 1" class="flex items-center gap-1 order-1 sm:order-2">
            <!-- min-h-[2.25rem]/px-3 : cible tactile ≥36px, plus généreuse
                 que la version Blade (px-2.5 py-1.5) pensée pour un pointeur
                 souris — ces boutons doivent rester utilisables au doigt sur
                 les écrans consultés depuis un téléphone (file de contact,
                 tableau de bord). -->
            <button type="button" :disabled="surPremierePage" @click="aller(meta.current_page - 1)"
                class="min-h-[2.25rem] px-3 py-1.5 border border-surface-border rounded-md text-[12.5px] font-semibold transition-colors"
                :class="surPremierePage
                    ? 'text-ink-faint bg-surface-2 cursor-not-allowed'
                    : 'text-ink bg-surface hover:bg-surface-2 active:scale-95'">
                ← Précédent
            </button>
            <span class="px-2 text-[12.5px] text-ink-muted whitespace-nowrap">
                Page {{ meta.current_page }} / {{ meta.last_page }}
            </span>
            <button type="button" :disabled="surDernierePage" @click="aller(meta.current_page + 1)"
                class="min-h-[2.25rem] px-3 py-1.5 border border-surface-border rounded-md text-[12.5px] font-semibold transition-colors"
                :class="surDernierePage
                    ? 'text-ink-faint bg-surface-2 cursor-not-allowed'
                    : 'text-ink bg-surface hover:bg-surface-2 active:scale-95'">
                Suivant →
            </button>
        </div>
    </div>
</template>
