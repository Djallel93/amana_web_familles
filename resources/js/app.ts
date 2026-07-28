// resources/js/app.ts
//
// Point d'entrée Vite pour AMANA Familles.
// Toast/ConfirmDialog/OfflineBanner/MobileSidebar/registerThemeToggle
// viennent maintenant de @amana/shared-ui (voir amana/shared) plutôt que
// d'une copie locale — c'était déjà, mot pour mot, le même code que
// amana_web_planning avant cette migration.

import { createApp } from "vue";

import { Toast, ConfirmDialog, OfflineBanner, MobileSidebar, registerThemeToggle } from "@amana/shared-ui";
import DetailPanel from "@/components/familles/DetailPanel.vue";
import IntakeForm from "@/components/intake/IntakeForm.vue";
import ImportManualGrid from "@/components/imports/ImportManualGrid.vue";
import FamillesStatistiques from "@/components/familles/FamillesStatistiques.vue";
import ActiviteStatistiques from "@/components/admin/ActiviteStatistiques.vue";

registerThemeToggle();

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
mountIfPresent("vue-intake-form", IntakeForm);
mountIfPresent("vue-import-manual-grid", ImportManualGrid);
mountIfPresent("vue-familles-statistiques", FamillesStatistiques);
mountIfPresent("vue-activite-statistiques", ActiviteStatistiques);

