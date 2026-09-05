<!-- resources/js/components/livraison/campagnes/CampagneProgressBar.vue -->
<!--
    Checklist "où en est cette campagne" — voir le prompt du 03/09/2026 :
    ajoutée après que l'absence de lien entre Campagne Detail et les
    écrans Pesée/Packaging/Chargement/Contacts ait rendu tout le workflow
    illisible (voir CampagneDetail.vue pour le panneau de liens rapides
    ajouté juste avant celle-ci). Le panneau de liens dit OÙ aller ; cette
    checklist dit OÙ ON EN EST — les deux ensemble remplacent ce que
    l'utilisateur devait sinon reconstituer de mémoire.

    Étapes déterminées côté serveur (voir
    CampagnesController::avancement()) plutôt que recalculées ici depuis
    des données déjà en mémoire côté CampagneDetail.vue : plusieurs
    étapes (pesée, packaging, chargement, statut des routes) ne sont
    chargées nulle part ailleurs sur cet écran, un seul aller-retour dédié
    évite d'ajouter 4 requêtes séparées.

    Une seule étape est marquée "en cours" à la fois — la première non
    terminée dans l'ordre du workflow — plutôt que de tenter de deviner un
    état "en cours" indépendant par étape (l'API ne renvoie que des
    booléens faits/pas faits, pas un statut à 3 valeurs par étape : plus
    simple à interpréter côté serveur, et suffisant pour l'usage "je sais
    où on en est d'un coup d'œil").
-->
<script setup lang="ts">
import { computed } from 'vue';

export interface AvancementCampagne {
    livraisons_generees: boolean;
    contacts_termines: boolean;
    contacts_en_cours: boolean;
    benevoles_notifies: boolean;
    routes_generees: boolean;
    pesee_demarree: boolean;
    packaging_termine: boolean;
    chargement_termine: boolean;
    livraison_en_cours: boolean;
    terminee: boolean;
    compteurs: {
        livraisons_total: number;
        livraisons_confirmees: number;
        routes_total: number;
        routes_terminees: number;
    };
}

const props = defineProps<{ avancement: AvancementCampagne | null }>();

interface Etape {
    label: string;
    fait: boolean;
}

const etapes = computed<Etape[]>(() => {
    const a = props.avancement;

    return [
        { label: 'Créée', fait: true },
        { label: 'Livraisons générées', fait: a?.livraisons_generees ?? false },
        { label: 'Contacts', fait: a?.contacts_termines ?? false },
        { label: 'Bénévoles notifiés', fait: a?.benevoles_notifies ?? false },
        { label: 'Routes générées', fait: a?.routes_generees ?? false },
        { label: 'Pesée', fait: a?.pesee_demarree ?? false },
        { label: 'Packaging', fait: a?.packaging_termine ?? false },
        { label: 'Chargement', fait: a?.chargement_termine ?? false },
        { label: 'Livraison', fait: a?.livraison_en_cours ?? false },
        { label: 'Terminée', fait: a?.terminee ?? false },
    ];
});

// Première étape pas encore faite = l'étape "en cours" — voir docblock.
const indexEnCours = computed(() => etapes.value.findIndex((e) => !e.fait));

function statutEtape(index: number): 'fait' | 'en_cours' | 'a_venir' {
    if (etapes.value[index].fait) return 'fait';
    if (index === indexEnCours.value) return 'en_cours';
    return 'a_venir';
}
</script>

<template>
    <ol v-if="avancement" class="flex flex-wrap items-center gap-x-1 gap-y-2 mb-6" aria-label="Avancement de la campagne">
        <li v-for="(etape, index) in etapes" :key="etape.label" class="flex items-center">
            <span
                class="flex items-center gap-1.5 text-[12px] px-2.5 py-1 rounded-full whitespace-nowrap"
                :class="{
                    'bg-emerald-100 text-emerald-700': statutEtape(index) === 'fait',
                    'bg-accent/10 text-accent font-semibold': statutEtape(index) === 'en_cours',
                    'bg-stone-100 text-ink-muted': statutEtape(index) === 'a_venir',
                }"
            >
                <span aria-hidden="true">{{ statutEtape(index) === 'fait' ? '✓' : statutEtape(index) === 'en_cours' ? '●' : '○' }}</span>
                {{ etape.label }}
            </span>
            <span v-if="index < etapes.length - 1" class="w-3 h-px bg-surface-border mx-0.5" aria-hidden="true"></span>
        </li>
    </ol>
</template>
