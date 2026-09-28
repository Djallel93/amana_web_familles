<!-- resources/js/pages/Livraison/CampagneDetail.vue -->
<!--
    Page Inertia "Détail campagne" — Section E4 du refactor (16/09/2026,
    cinquième chunk du domaine livraison), remplace resources/views/
    livraison/campagne-detail.blade.php (supprimée dans ce même chunk,
    plus aucun consommateur une fois CampagnesController::show()
    converti en Inertia::render()).

    Cette page ne porte que le lien de retour que portait la Blade ; tout
    le reste (HQ/commentaire, journées, sélection des familles éligibles,
    génération livraisons/routes, rangée de navigation) vit dans
    CampagneDetail.vue, désormais enfant Vue normal plutôt qu'îlot monté
    par app.ts.

    Retour en <Link> (campagnes/index est déjà une page Inertia depuis le
    chunk précédent). Les neuf boutons de navigation de CampagneDetail.vue
    lui-même restent en <a href> classiques — sept de leurs neuf cibles
    sont désormais Inertia, seuls réception/pesée/packaging/chargement
    restent en Blade (hors périmètre de ce refactor) — voir le docblock
    de CampagnesController::show() pour l'historique de cette décision.
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CampagneDetail from '../../components/livraison/campagnes/CampagneDetail.vue';
import type {
    Campagne,
    Organisation,
    Quartier,
    Secteur,
    Ville,
} from '../../components/livraison/shared/types';

defineProps<{
    campagne: Campagne;
    quartiers: Quartier[];
    villes: Ville[];
    secteurs: Secteur[];
    organisations: Organisation[];
    googlePlacesKey: string;
    eligiblesUrl: string;
    genererLivraisonsUrl: string;
    genererRoutesUrl: string;
    queueUrl: string;
    contactsStatistiquesUrl: string;
    benevolesUrl: string;
    equipesUrl: string;
    receptionUrl: string;
    ajouterJourneeUrl: string;
    avancementUrl: string;
    updateUrl: string;
    contactsUrl: string;
    peseeUrl: string;
    packagingUrl: string;
    chargementUrl: string;
    // Ajouté le 24/09/2026 (prompt de cette date §2, dernier point).
    retraitHqUrl: string;
    suiviLivraisonUrl: string;
    statistiquesUrl: string;
    retourUrl: string;
}>();
</script>

<template>
    <Head title="Campagne — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link :href="retourUrl"
            class="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink border border-surface-border rounded-lg px-3 py-1.5 mb-4 hover:bg-stone-50">
        ← Retour aux campagnes
        </Link>

        <CampagneDetail :campagne="campagne" :quartiers="quartiers" :villes="villes" :secteurs="secteurs"
            :organisations="organisations" :google-places-key="googlePlacesKey" :eligibles-url="eligiblesUrl"
            :generer-livraisons-url="genererLivraisonsUrl" :generer-routes-url="genererRoutesUrl"
            :queue-url="queueUrl" :contacts-statistiques-url="contactsStatistiquesUrl" :benevoles-url="benevolesUrl"
            :equipes-url="equipesUrl" :reception-url="receptionUrl" :ajouter-journee-url="ajouterJourneeUrl"
            :avancement-url="avancementUrl" :update-url="updateUrl" :contacts-url="contactsUrl"
            :pesee-url="peseeUrl" :packaging-url="packagingUrl" :chargement-url="chargementUrl"
            :retrait-hq-url="retraitHqUrl"
            :suivi-livraison-url="suiviLivraisonUrl" :statistiques-url="statistiquesUrl" />
    </div>
</template>
