// resources/js/components/familles/useDossierPanel.ts
//
// Scénario 5 du chantier "polling live" (19/09/2026) — le Dossier Panel
// (DetailPanel.vue) est-il ouvert ? Consommé par useLiveDossiers pour ne
// PAS rafraîchir le tableau derrière un panneau en cours d'édition.
//
// Singleton de module (un `ref` partagé par tous les importeurs), pas un
// store Pinia : même patron que useNotifications.ts (@amana/shared-ui) —
// deux composants d'une même page (la page et DetailPanel) ont besoin de
// lire/écrire UN booléen, ce qui ne justifie aucune dépendance. Pinia n'est
// pas une dépendance de ce projet et ce besoin ne la rend pas nécessaire.
//
// Le drapeau vit au niveau du module et survit donc à un changement de
// page Inertia : DetailPanel le remet à false dans son onUnmounted, pour
// qu'un panneau fermé par navigation ne laisse pas le polling de la page
// suivante bloqué.

import { readonly, ref } from 'vue';

const panneauOuvert = ref(false);

/** Appelé UNIQUEMENT par DetailPanel.vue (propriétaire de l'état d'ouverture). */
export function definirPanneauOuvert(ouvert: boolean): void {
    panneauOuvert.value = ouvert;
}

export function useDossierPanel() {
    return { panneauOuvert: readonly(panneauOuvert) };
}
