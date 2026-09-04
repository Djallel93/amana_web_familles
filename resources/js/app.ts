// resources/js/app.ts
//
// Point d'entrée Vite pour AMANA Familles.
// Toast/ConfirmDialog/OfflineBanner/MobileSidebar/registerThemeToggle
// viennent maintenant de @amana/shared-ui (voir amana/shared) plutôt que
// d'une copie locale — c'était déjà, mot pour mot, le même code que
// amana_web_planning avant cette migration.

import { createApp } from "vue";

import { Toast, ConfirmDialog, OfflineBanner, UrgentAlertBar, NotificationBell, MobileSidebar, registerThemeToggle, registerConfirmForms, configureNotifications } from "@amana/shared-ui";
import DetailPanel from "@/components/familles/DetailPanel.vue";
import ReverseSyncPanel from "@/components/familles/ReverseSyncPanel.vue";
import IntakeForm from "@/components/intake/IntakeForm.vue";
import BenevoleForm from "@/components/benevole/BenevoleForm.vue";
import ImportManualGrid from "@/components/imports/ImportManualGrid.vue";
import ImportOverlay from "@/components/imports/ImportOverlay.vue";
import FamillesStatistiques from "@/components/familles/FamillesStatistiques.vue";
import ActiviteStatistiques from "@/components/admin/ActiviteStatistiques.vue";
import HotelAddressAutocomplete from "@/components/admin/HotelAddressAutocomplete.vue";
import CampagnesIndex from "@/components/livraison/campagnes/CampagnesIndex.vue";
import CampagneDetail from "@/components/livraison/campagnes/CampagneDetail.vue";
import ContactsQueue from "@/components/livraison/contacts/ContactsQueue.vue";
import LiveBoard from "@/components/livraison/tableau-de-bord/LiveBoard.vue";
import LivraisonStatistiques from "@/components/livraison/statistiques/LivraisonStatistiques.vue";

registerThemeToggle();
// Remplace confirm() natif par ConfirmDialog.vue pour tout <form data-confirm="...">
// rendu en Blade classique (admin/verifications, admin/imports, personnes) —
// voir amana_shared_ui/src/lib/confirmForms.ts pour l'usage complet.
registerConfirmForms();

// Centre de notifications partagé (voir le prompt du 03/09/2026) — routes
// exposées par amana-shared::NotificationsController, enregistrées côté
// familles sous /notifications (voir routes/web.php).
configureNotifications({ basePath: "/notifications" });

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
mountIfPresent("vue-urgent-alert-bar", UrgentAlertBar);
mountIfPresent("vue-notification-bell", NotificationBell);
mountIfPresent("vue-mobile-sidebar", MobileSidebar);
mountIfPresent("vue-famille-detail", DetailPanel);
mountIfPresent("vue-reverse-sync-panel", ReverseSyncPanel);
mountIfPresent("vue-intake-form", IntakeForm);
mountIfPresent("vue-benevole-form", BenevoleForm);
mountIfPresent("vue-import-manual-grid", ImportManualGrid);
mountIfPresent("vue-import-overlay", ImportOverlay);
mountIfPresent("vue-familles-statistiques", FamillesStatistiques);
mountIfPresent("vue-activite-statistiques", ActiviteStatistiques);
mountIfPresent("vue-hotel-address-autocomplete", HotelAddressAutocomplete);
mountIfPresent("vue-livraison-campagnes-index", CampagnesIndex);
mountIfPresent("vue-livraison-campagne-detail", CampagneDetail);
mountIfPresent("vue-livraison-contacts-queue", ContactsQueue);
mountIfPresent("vue-livraison-tableau-de-bord", LiveBoard);
mountIfPresent("vue-livraison-statistiques", LivraisonStatistiques);

