<!-- resources/js/pages/Livraison/Equipes.vue -->
<!--
    Page Inertia "Équipes d'une campagne" — Section E4 du refactor
    (16/09/2026, premier chunk du domaine livraison), remplace
    resources/views/livraison/equipe-membres.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    EquipeMembresController::index() converti en Inertia::render()).

    Cette page ne fait que reprendre le chrome que portait la Blade (lien
    de retour + titre) ; tout le reste vit dans EquipeMembresQueue.vue,
    désormais enfant Vue normal de cette page plutôt qu'îlot monté par
    app.ts sur #vue-livraison-equipe-membres.

    Le lien de retour passe par <Link> plutôt qu'un <a> : la page campagne
    n'est PAS encore migrée (campagne-detail.blade.php, chunk ultérieur),
    mais Inertia retombe tout seul sur une navigation complète quand la
    réponse n'est pas une réponse Inertia — comportement correct dans les
    deux cas, et rien à changer ici quand ce chunk-là arrivera.
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EquipeMembresQueue, {
    type LigneEquipe,
} from '../../components/livraison/campagnes/EquipeMembresQueue.vue';
import type { Campagne } from '../../components/livraison/shared/types';

defineProps<{
    campagne: Campagne;
    lignes: LigneEquipe[];
    retourUrl: string;
    ajouterUrl: string;
    retirerUrlTemplate: string;
}>();
</script>

<template>
    <Head title="Équipes — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
        ← Retour à la campagne
        </Link>

        <EquipeMembresQueue :campagne="campagne" :lignes="lignes" :ajouter-url="ajouterUrl"
            :retirer-url-template="retirerUrlTemplate" />
    </div>
</template>
