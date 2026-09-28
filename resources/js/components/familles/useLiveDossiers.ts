// resources/js/components/familles/useLiveDossiers.ts
//
// Scénario 5 du chantier "polling live" (19/09/2026) — le tableau des
// dossiers (Familles/Index.vue, Familles/Nouvelles.vue) se recharge tout
// seul, pour qu'un changement de statut ou un verrou posé par un autre
// membre du staff (ouverture du Dossier Panel : voir
// FamillesController::show(), qui pose le verrou ET bascule etat_dossier
// sur 'En cours' d'un seul saveQuietly()) soit visible sans recharger la
// page — le staff se sert de ce statut pour savoir quels dossiers sont
// libres.
//
// ── RÈGLE DE COHÉRENCE POUR TOUT LE POLLING DE L'APPLICATION ──────────────
// Il n'y a volontairement PAS de "moteur de polling" maison ici. Trois cas,
// un outil chacun :
//  1. La donnée EST une prop de page Inertia (ce fichier : `familles`) →
//     usePoll() d'Inertia v3 (@inertiajs/vue3), qui fournit déjà
//     l'intervalle, le ralentissement onglet masqué et le nettoyage au
//     démontage. Ce composable ne fait QUE lui donner ses options et sa
//     garde (voir ci-dessous) — c'est de la configuration partagée entre
//     les deux pages, pas un second mécanisme de polling.
//  2. La donnée vient d'un endpoint JSON dans un composant Vue (ex.
//     LiveBoard.vue : routes/incidents/statistiques) → usePoll() ne
//     convient pas : router.poll() est câblé sur un rechargement de PAGE
//     (doReload) et n'accepte pas de callback arbitraire. Minuteur maison
//     avec les mêmes garde-fous (onglet masqué, pas de chevauchement,
//     nettoyage au démontage) — voir LiveBoard.vue.
//  3. Page Blade sans Vue/Inertia (packaging, chargement…) → script inline
//     avec setInterval, mêmes garde-fous.
//
// ── Choix propres à ce composable ─────────────────────────────────────────
//  - Portée `only: ['familles']` : la prop du tableau, rendue par la MÊME
//    action de contrôleur (index()/nouvelles()) et le même
//    FamilleListItemResource que le chargement initial. NB : index()
//    évalue toutes ses props, `only` allège la réponse, pas les requêtes
//    SQL — un poll coûte environ un chargement de page.
//  - `async: true` : requête hors du flux "synchrone" d'Inertia — pas de
//    barre de progression, n'interrompt JAMAIS la navigation de
//    l'utilisateur (tri, filtre, page), et Inertia l'annule si
//    l'utilisateur navigue ailleurs.
//  - mode 'cancel' (et non 'rest') : en mode 'rest', le tick suivant n'est
//    planifié que par onFinish ; or un onBefore qui renvoie false (notre
//    garde) n'appelle jamais onFinish — le polling mourrait à jamais dès
//    le premier tick bloqué par un panneau ouvert. En 'cancel', le tick
//    suivant est sur minuterie fixe, et une requête encore en vol au tick
//    suivant est annulée (pas d'empilement côté client).
//  - Garde (onBefore) : aucun rechargement tant qu'un Dossier Panel est
//    ouvert (la ligne dessous ne doit pas bouger pendant l'édition — le
//    tick suivant après fermeture rattrape), ni pendant qu'une visite de
//    l'UTILISATEUR est en cours : un poll parti avec l'ancienne URL
//    (tri/filtre en train de changer) remplacerait `familles` par des
//    données de l'ancienne requête. Une visite "en cours" depuis plus de
//    DELAI_MAX_VISITE_MS est ignorée (filet si un `finish` était perdu).
//  - Une requête de poll déjà en vol est annulée à l'ouverture du panneau
//    et au démarrage d'une visite utilisateur. Pour une visite vers une
//    AUTRE url (tri, filtre, page) Inertia annule déjà lui-même les
//    requêtes async en vol sur cette page ; pour une visite vers la MÊME
//    url (re-clic, rechargement) il les laisse courir, et la réponse
//    d'un poll parti avant pourrait alors écraser des données plus
//    fraîches — d'où l'annulation explicite ici.
//  - Onglet masqué : ralentissement par défaut d'Inertia (1 tick sur 10),
//    conservé volontairement ; au retour sur l'onglet, un rechargement
//    immédiat (comme les autres pollers de l'application).

import { router, usePoll } from '@inertiajs/vue3';
import { onUnmounted, watch } from 'vue';
import { useDossierPanel } from './useDossierPanel';

export const LIVE_DOSSIERS_INTERVALLE_MS = 15000;
const DELAI_MAX_VISITE_MS = 30000;

export function useLiveDossiers(intervalleMs: number = LIVE_DOSSIERS_INTERVALLE_MS): void {
    const { panneauOuvert } = useDossierPanel();

    let visiteUtilisateurDepuis: number | null = null;
    let annulerRechargement: (() => void) | null = null;

    function bloque(): boolean {
        if (panneauOuvert.value) return true;
        return visiteUtilisateurDepuis !== null && Date.now() - visiteUtilisateurDepuis < DELAI_MAX_VISITE_MS;
    }

    function optionsRechargement() {
        return {
            only: ['familles'],
            async: true,
            // Renvoyer false annule ce tick (voir l'en-tête : pourquoi mode 'cancel').
            onBefore: () => !bloque(),
            onCancelToken: (jeton: { cancel: () => void }) => {
                annulerRechargement = jeton.cancel;
            },
            onFinish: () => {
                annulerRechargement = null;
            },
        };
    }

    usePoll(intervalleMs, optionsRechargement, { mode: 'cancel' });

    // Visites de l'UTILISATEUR (non async : tri, filtre, pagination, ouverture
    // ?ouvrir=…). Les rechargements de poll sont async et ne comptent pas.
    const desabonnements = [
        router.on('start', (evenement) => {
            if (evenement.detail.visit.async) return;
            visiteUtilisateurDepuis = Date.now();
            annulerRechargement?.();
        }),
        router.on('finish', (evenement) => {
            if (!evenement.detail.visit.async) visiteUtilisateurDepuis = null;
        }),
    ];

    watch(panneauOuvert, (ouvert) => {
        if (ouvert) annulerRechargement?.();
    });

    function auChangementDeVisibilite(): void {
        // Même garde onBefore que le polling : pas de rechargement si un
        // panneau est ouvert ou si une visite utilisateur est en cours.
        if (!document.hidden) router.reload(optionsRechargement());
    }
    document.addEventListener('visibilitychange', auChangementDeVisibilite);

    onUnmounted(() => {
        desabonnements.forEach((desabonner) => desabonner());
        document.removeEventListener('visibilitychange', auChangementDeVisibilite);
    });
}
