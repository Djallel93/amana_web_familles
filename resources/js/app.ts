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

import { createApp, defineAsyncComponent, h, type Component, type DefineComponent } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";

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

// ── Bootstrap Inertia (Section E4 du refactor, 12/09/2026) ──────────────
//
// Garde explicite sur #app avant d'appeler createInertiaApp() : ce même
// app.ts reste le point d'entrée Vite UNIQUE pour toute l'app (voir
// commentaire en tête de fichier), donc chargé aussi sur les pages pas
// encore migrées, qui n'ont pas de <div id="app" data-page="..."> (voir
// resources/views/app.blade.php, qui coexiste avec layouts/app.blade.php
// plutôt que de le remplacer). createInertiaApp() lit el.dataset.page
// sans vérifier que l'élément existe — un appel inconditionnel ferait
// planter le JS sur ces pages, exactement le genre d'erreur que
// mountIfPresent() ci-dessous évite déjà pour les îlots page-spécifiques.
//
// resolve() : resolvePageComponent() (laravel-vite-plugin, déjà une
// dépendance existante du projet — scaffolding Laravel+Vite standard)
// plutôt qu'un import.meta.glob() nu — voir le commentaire à l'intérieur
// de resolve() ci-dessous pour le détail du typage retenu (une première
// tentative plus simple ne passait pas vue-tsc --noEmit). Chaque page
// sous resources/js/pages/ reste découverte automatiquement et son
// propre chunk Vite, même rationale de découpage que lazy() ci-dessous.
if (document.getElementById("app")) {
    createInertiaApp({
        // resolve() doit renvoyer DefineComponent | Promise<DefineComponent> |
        // { default: DefineComponent } (type ComponentResolver
        // d'@inertiajs/vue3) — resolvePageComponent() (laravel-vite-plugin)
        // ne déballe PAS .default en interne (vérifié dans son propre
        // code source, node_modules/laravel-vite-plugin/inertia-helpers/
        // index.js : `return typeof page === 'function' ? page() : page`),
        // donc renvoyer directement sa Promise<{ default: DefineComponent }>
        // ne correspond à aucune des trois formes attendues. async/await +
        // .default explicite plutôt qu'un simple pass-through.
        resolve: async (name) => {
            const module = await resolvePageComponent<{
                default: DefineComponent;
            }>(
                `./pages/${name}.vue`,
                import.meta.glob<{ default: DefineComponent }>(
                    "./pages/**/*.vue",
                ),
            );
            return module.default;
        },
        setup({ el, App, props, plugin }) {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el);
        },
    });
}

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
    "vue-familles-filtres",
    lazy(() => import("@/components/familles/FamilleFiltresBar.vue")),
);
mountIfPresent(
    "vue-reverse-sync-panel",
    lazy(() => import("@/components/familles/ReverseSyncPanel.vue")),
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
