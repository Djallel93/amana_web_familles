// resources/js/app.ts
//
// Point d'entrée Vite pour AMANA Familles.
// Toast/ConfirmDialog/OfflineBanner/MobileSidebar/registerThemeToggle
// viennent maintenant de @amana/shared-ui (voir amana/shared) plutôt que
// d'une copie locale — c'était déjà, mot pour mot, le même code que
// amana_web_planning avant cette migration.
//
// Îlots spécifiques à une page (DetailPanel, CampagnesIndex, LiveBoard...)
// chargés via defineAsyncComponent()/import() dynamique plutôt qu'importés
// statiquement (décision du 07/09/2026, voir avertissement Vite "chunks
// larger than 500 kB") : à défaut, TOUTES ces vues (+ Chart.js, utilisé par
// FamillesStatistiques/ActiviteStatistiques/LivraisonStatistiques) finissent
// dans le même app.js quelle que soit la page visitée, puisque app.ts est un
// point d'entrée unique. mountIfPresent() ne monte déjà que l'îlot dont le
// point de montage existe dans le DOM de la page courante — passer un loader
// plutôt qu'un composant importé statiquement suffit donc à ce que Vite/
// Rollup découpe chacun de ces composants dans son propre chunk, chargé à la
// demande au lieu d'être toujours téléchargé. Toast/ConfirmDialog/
// OfflineBanner/UrgentAlertBar/NotificationBell/MobileSidebar restent en
// import statique : ce sont des éléments de chrome montés sur (quasi) toutes
// les pages, déjà légers (composants partagés depuis @amana/shared-ui), rien
// à gagner à les charger dynamiquement.

import { createApp, defineAsyncComponent, type Component } from "vue";

import {
    Toast,
    ConfirmDialog,
    OfflineBanner,
    UrgentAlertBar,
    NotificationBell,
    MobileSidebar,
    registerThemeToggle,
    registerConfirmForms,
    configureNotifications,
} from "@amana/shared-ui";

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

/**
 * Enveloppe un import() dynamique dans defineAsyncComponent() — voir
 * commentaire en tête de fichier. Un simple alias pour éviter de répéter
 * `defineAsyncComponent(() => import(...))` sur chacun des îlots
 * spécifiques ci-dessous.
 */
function lazy(loader: () => Promise<{ default: Component }>): Component {
    return defineAsyncComponent(loader);
}

mountIfPresent("vue-toast", Toast);
mountIfPresent("vue-confirm-dialog", ConfirmDialog);
mountIfPresent("vue-offline-banner", OfflineBanner);
mountIfPresent("vue-urgent-alert-bar", UrgentAlertBar);
mountIfPresent("vue-notification-bell", NotificationBell);
mountIfPresent("vue-mobile-sidebar", MobileSidebar);
mountIfPresent(
    "vue-famille-detail",
    lazy(() => import("@/components/familles/DetailPanel.vue")),
);
mountIfPresent(
    "vue-familles-filtres",
    lazy(() => import("@/components/familles/FamilleFiltresBar.vue")),
);
mountIfPresent(
    "vue-reverse-sync-panel",
    lazy(() => import("@/components/familles/ReverseSyncPanel.vue")),
);
mountIfPresent(
    "vue-intake-form",
    lazy(() => import("@/components/intake/IntakeForm.vue")),
);
mountIfPresent(
    "vue-benevole-form",
    lazy(() => import("@/components/benevole/BenevoleForm.vue")),
);
mountIfPresent(
    "vue-import-manual-grid",
    lazy(() => import("@/components/imports/ImportManualGrid.vue")),
);
mountIfPresent(
    "vue-import-overlay",
    lazy(() => import("@/components/imports/ImportOverlay.vue")),
);
mountIfPresent(
    "vue-familles-statistiques",
    lazy(() => import("@/components/familles/FamillesStatistiques.vue")),
);
mountIfPresent(
    "vue-activite-statistiques",
    lazy(() => import("@/components/admin/ActiviteStatistiques.vue")),
);
mountIfPresent(
    "vue-hotel-address-autocomplete",
    lazy(() => import("@/components/admin/HotelAddressAutocomplete.vue")),
);
mountIfPresent(
    "vue-settings-tabs",
    lazy(() => import("@/components/admin/SettingsTabs.vue")),
);
mountIfPresent(
    "vue-hq-coordinates-autocomplete",
    lazy(() => import("@/components/admin/HqCoordinatesAutocomplete.vue")),
);
mountIfPresent(
    "vue-livraison-campagnes-index",
    lazy(() => import("@/components/livraison/campagnes/CampagnesIndex.vue")),
);
mountIfPresent(
    "vue-livraison-campagne-detail",
    lazy(() => import("@/components/livraison/campagnes/CampagneDetail.vue")),
);
mountIfPresent(
    "vue-livraison-contacts-queue",
    lazy(() => import("@/components/livraison/contacts/ContactsQueue.vue")),
);
mountIfPresent(
    "vue-livraison-benevole-disponibilite",
    lazy(
        () =>
            import("@/components/livraison/campagnes/BenevoleDisponibiliteQueue.vue"),
    ),
);
mountIfPresent(
    "vue-livraison-equipe-membres",
    lazy(
        () =>
            import("@/components/livraison/campagnes/EquipeMembresQueue.vue"),
    ),
);
mountIfPresent(
    "vue-livraison-suivi-livraison",
    lazy(() => import("@/components/livraison/tableau-de-bord/LiveBoard.vue")),
);
mountIfPresent(
    "vue-livraison-statistiques",
    lazy(
        () =>
            import("@/components/livraison/statistiques/LivraisonStatistiques.vue"),
    ),
);
