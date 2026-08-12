<!-- resources/js/components/imports/ImportOverlay.vue -->
<!--
    Remplace l'ancien overlay plein écran fait main (div#import-overlay,
    classList.add/remove('hidden') dans admin/imports/create.blade.php et
    ImportManualGrid.vue) par le composant Modal partagé — pour rester
    cohérent avec le reste de l'app (voir DetailPanel.vue, seul autre
    consommateur de <Modal> ici).

    Modal se ferme normalement au clic sur le backdrop ou à Escape — pas
    souhaitable pendant un import (la requête est déjà partie, pas de
    retour en arrière possible), donc pas de bouton × (pas de slot
    "header") et l'event @close est intentionnellement ignoré tant que
    l'import est en cours — voir onCloseAttempt().

    Monté une seule fois dans la page (#vue-import-overlay, voir app.ts)
    et piloté depuis deux endroits différents : le formulaire CSV
    (vanilla JS classique) et ImportManualGrid.vue (fetch async) — pas de
    lien parent/enfant Vue possible entre ces composants montés
    séparément, donc exposition sur window, même pattern que
    DetailPanel.vue::openFamilleDetail.
-->
<script setup lang="ts">
import { ref } from 'vue';
import { Modal } from '@amana/shared-ui';

const isOpen = ref(false);

function show(): void {
    isOpen.value = true;
}

function hide(): void {
    isOpen.value = false;
}

// no-op volontaire : fermeture uniquement programmatique via hide(),
// jamais par interaction utilisateur pendant un import en cours.
function onCloseAttempt(): void {}

declare global {
    interface Window {
        showImportOverlay: () => void;
        hideImportOverlay: () => void;
    }
}

window.showImportOverlay = show;
window.hideImportOverlay = hide;
</script>

<template>
    <Modal :open="isOpen" max-width="max-w-xs" @close="onCloseAttempt">
        <div class="text-center py-2">
            <p class="text-[13.5px] font-semibold text-ink mb-4">Import en cours…</p>
            <div class="h-2 w-full bg-surface-2 rounded-full overflow-hidden">
                <div class="h-full w-1/3 bg-accent rounded-full animate-import-sweep"></div>
            </div>
            <p class="text-[11.5px] text-ink-muted mt-3">Merci de patienter, ne fermez pas cette page.</p>
        </div>
    </Modal>
</template>

<style scoped>
@keyframes import-sweep {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(300%); }
}
.animate-import-sweep {
    animation: import-sweep 1.1s ease-in-out infinite;
}
</style>
