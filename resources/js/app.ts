// resources/js/app.ts
//
// Point d'entrée Vite pour AMANA Familles.
// Toast/ConfirmDialog/OfflineBanner/MobileSidebar/registerThemeToggle
// viennent maintenant de @amana/shared-ui (voir amana/shared) plutôt que
// d'une copie locale — c'était déjà, mot pour mot, le même code que
// amana_web_planning avant cette migration.

import { createApp } from "vue";

import { Toast, ConfirmDialog, OfflineBanner, MobileSidebar, registerThemeToggle, registerConfirmForms } from "@amana/shared-ui";
import DetailPanel from "@/components/familles/DetailPanel.vue";
import ReverseSyncPanel from "@/components/familles/ReverseSyncPanel.vue";
import IntakeForm from "@/components/intake/IntakeForm.vue";
import BenevoleForm from "@/components/benevole/BenevoleForm.vue";
import ImportManualGrid from "@/components/imports/ImportManualGrid.vue";
import ImportOverlay from "@/components/imports/ImportOverlay.vue";
import FamillesStatistiques from "@/components/familles/FamillesStatistiques.vue";
import ActiviteStatistiques from "@/components/admin/ActiviteStatistiques.vue";

registerThemeToggle();
// Remplace confirm() natif par ConfirmDialog.vue pour tout <form data-confirm="...">
// rendu en Blade classique (admin/verifications, admin/imports, personnes) —
// voir amana_shared_ui/src/lib/confirmForms.ts pour l'usage complet.
registerConfirmForms();

function mountIfPresent(
    selector: string,
    component: Parameters<typeof createApp>[0],
): void {
    const el = document.getElementById(selector);
    if (el) createApp(component).mount(el);
}

mountIfPresent("vue-toast", Toast);
mountIfPresent("vue-confirm-dialog", ConfirmDialog);
mountIfPresent("vue-offline-banner", OfflineBanner);
mountIfPresent("vue-mobile-sidebar", MobileSidebar);
mountIfPresent("vue-famille-detail", DetailPanel);
mountIfPresent("vue-reverse-sync-panel", ReverseSyncPanel);
mountIfPresent("vue-intake-form", IntakeForm);
mountIfPresent("vue-benevole-form", BenevoleForm);
mountIfPresent("vue-import-manual-grid", ImportManualGrid);
mountIfPresent("vue-import-overlay", ImportOverlay);
mountIfPresent("vue-familles-statistiques", FamillesStatistiques);
mountIfPresent("vue-activite-statistiques", ActiviteStatistiques);

