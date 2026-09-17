<!-- resources/js/pages/Livraison/Benevoles.vue -->
<!--
    Page Inertia "Suivi des bénévoles" d'une campagne — Section E4 du
    refactor (16/09/2026, deuxième chunk du domaine livraison), remplace
    resources/views/livraison/benevole-disponibilite.blade.php (supprimée
    dans ce même chunk, plus aucun consommateur une fois
    BenevoleDisponibiliteController::index() converti en
    Inertia::render()).

    Même forme que Livraison/Equipes.vue (chunk précédent) : cette page ne
    porte que le chrome de l'ancienne Blade (lien de retour + titre), tout
    le reste vit dans BenevoleDisponibiliteQueue.vue, désormais enfant Vue
    normal plutôt qu'îlot monté par app.ts.

    Différence avec Equipes.vue : aucune prop `lignes` ici. Le tableau
    reste chargé par XHR via queue() (filtres journée/statut/recherche —
    voir le docblock de BenevoleDisponibiliteController::index()).

    <Link> plutôt qu'un <a> pour le retour : au moment de ce chunk, la
    page campagne n'était pas encore migrée, mais Inertia retombe tout
    seul sur une navigation complète quand la réponse n'est pas une
    réponse Inertia — d'où l'absence de changement nécessaire ici une
    fois campagne-detail.blade.php converti à son tour (chunk du
    16/09/2026, cinquième du domaine livraison).
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BenevoleDisponibiliteQueue from '../../components/livraison/campagnes/BenevoleDisponibiliteQueue.vue';
import type { Campagne } from '../../components/livraison/shared/types';

defineProps<{
    campagne: Campagne;
    retourUrl: string;
    queueUrl: string;
    mettreAJourUrlTemplate: string;
    notifierBenevolesUrl: string;
    personneEditUrlTemplate: string;
}>();
</script>

<template>
    <Head title="Suivi des bénévoles — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
        ← Retour à la campagne
        </Link>

        <BenevoleDisponibiliteQueue :campagne="campagne" :queue-url="queueUrl"
            :mettre-a-jour-url-template="mettreAJourUrlTemplate" :notifier-benevoles-url="notifierBenevolesUrl"
            :personne-edit-url-template="personneEditUrlTemplate" />
    </div>
</template>
