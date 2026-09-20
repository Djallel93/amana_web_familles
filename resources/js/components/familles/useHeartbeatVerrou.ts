// resources/js/components/familles/useHeartbeatVerrou.ts
//
// Battement de cœur du verrou d'édition d'un dossier (19/09/2026, suite du
// Scénario 5 du chantier "polling live") — utilisé par DetailPanel.vue.
//
// Problème : FamillesController::show() ne pose locked_at qu'à l'ouverture
// du panneau, et le serveur considère un verrou libre passé
// Famille::VERROU_TTL_MINUTES (20 min). Une édition plus longue laissait
// donc un autre utilisateur ouvrir le même dossier, et update() relâche le
// verrou "quel que soit qui le détenait" : le dernier enregistrement
// l'emporte en silence.
//
// Ce composable POSTe périodiquement sur renouvelerVerrou() (renouvelle le
// verrou, ou le reprend s'il a été nettoyé/expiré entre-temps) tant que le
// panneau est ouvert ET que l'utilisateur est actif. Si un autre
// utilisateur a repris le dossier (423), `verrouPerdu` porte son nom pour
// que le panneau affiche un bandeau d'avertissement — l'enregistrement reste
// possible (décision du 19/09/2026).
//
// Cas 3 de la règle de cohérence du polling (voir useLiveDossiers.ts) : ce
// n'est pas une prop de page mais un POST pilotable à la main — minuterie
// maison, mêmes garde-fous que les autres (pas de chevauchement, requête en
// vol annulée à l'arrêt, nettoyage complet).
//
// ── Garde-fous propres à ce composable ────────────────────────────────────
//  - INACTIVITÉ : aucun battement au-delà de HEARTBEAT_INACTIVITE_MAX_MS sans
//    la moindre saisie. Sans cette limite un panneau oublié ouvert
//    garderait son verrou indéfiniment, là où aujourd'hui il expire seul.
//    Au retour de l'utilisateur, la première action renouvelle aussitôt
//    (ou signale la perte du verrou).
//  - PAUSE avant un enregistrement : update() vide le verrou ; un battement
//    qui arriverait ENSUITE le reprendrait et rebasculerait le dossier sur
//    'En cours' juste après l'enregistrement. DetailPanel appelle donc
//    pause() avant la requête, reprendre() si elle échoue, et laisse le
//    battement arrêté si elle réussit.
//  - Un 401/419 (session expirée) ou 404 (dossier supprimé) arrête
//    définitivement les battements : il n'y a plus rien à protéger.
//    Toute autre erreur (réseau, 5xx) est réessayée au battement suivant.

import { readonly, ref } from 'vue';

export const HEARTBEAT_INTERVALLE_MS = 5 * 60 * 1000;
export const HEARTBEAT_INACTIVITE_MAX_MS = 10 * 60 * 1000;

const EVENEMENTS_ACTIVITE = ['pointerdown', 'keydown', 'input', 'wheel', 'touchstart', 'scroll'] as const;
const STATUTS_TERMINAUX = [401, 404, 419];

export interface VerrouPerdu {
    /** Nom du détenteur actuel, null si le serveur ne l'a pas fourni. */
    par: string | null;
}

interface Options {
    /** URL du battement pour ce dossier, ou '' si non fournie (aucun battement). */
    urlPour: (idDossier: number) => string;
    csrf: () => string;
}

export function useHeartbeatVerrou({ urlPour, csrf }: Options) {
    const verrouPerdu = ref<VerrouPerdu | null>(null);

    let idDossier: number | null = null;
    let actif = false;
    let minuterie: ReturnType<typeof setInterval> | undefined;
    let controleur: AbortController | null = null;
    let derniereActivite = 0;
    let derniereTentative = 0;

    async function renouveler(): Promise<void> {
        if (!actif || idDossier === null || controleur) return;
        const url = urlPour(idDossier);
        if (!url) return;

        derniereTentative = Date.now();
        const mien = new AbortController();
        controleur = mien;

        try {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                signal: mien.signal,
            });
            if (!actif) return;

            if (reponse.ok) {
                verrouPerdu.value = null;
            } else if (reponse.status === 423) {
                const corps = await reponse.json().catch(() => ({}));
                if (actif) verrouPerdu.value = { par: corps?.locked_by_nom ?? null };
            } else if (STATUTS_TERMINAUX.includes(reponse.status)) {
                pause();
            }
        } catch {
            // Réseau coupé ou requête annulée : réessayé au prochain battement.
        } finally {
            if (controleur === mien) controleur = null;
        }
    }

    function surActivite(): void {
        derniereActivite = Date.now();
        // Retour après une longue pause / veille de la machine / inactivité :
        // renouvelle (ou constate la perte) sans attendre le prochain battement.
        if (Date.now() - derniereTentative >= HEARTBEAT_INTERVALLE_MS) void renouveler();
    }

    function surBattement(): void {
        if (Date.now() - derniereActivite > HEARTBEAT_INACTIVITE_MAX_MS) return;
        // Une action de l'utilisateur vient peut-être de renouveler : pas de doublon.
        if (Date.now() - derniereTentative < HEARTBEAT_INTERVALLE_MS / 2) return;
        void renouveler();
    }

    function surVisibilite(): void {
        if (!document.hidden) surActivite();
    }

    function lancer(): void {
        if (actif || idDossier === null || !urlPour(idDossier)) return;
        actif = true;
        derniereActivite = Date.now();
        derniereTentative = Date.now(); // le verrou vient d'être pris (ouverture) ou l'était à l'instant
        minuterie = setInterval(surBattement, HEARTBEAT_INTERVALLE_MS);
        EVENEMENTS_ACTIVITE.forEach((e) => document.addEventListener(e, surActivite, { capture: true, passive: true }));
        document.addEventListener('visibilitychange', surVisibilite);
    }

    /** Arrête minuterie, écouteurs et requête en vol — conserve le dossier et le bandeau. */
    function pause(): void {
        actif = false;
        if (minuterie !== undefined) clearInterval(minuterie);
        minuterie = undefined;
        EVENEMENTS_ACTIVITE.forEach((e) => document.removeEventListener(e, surActivite, { capture: true }));
        document.removeEventListener('visibilitychange', surVisibilite);
        controleur?.abort();
        controleur = null;
    }

    /** Dossier chargé (verrou pris par show()) : démarre les battements. */
    function demarrer(id: number): void {
        arreter();
        idDossier = id;
        lancer();
    }

    /** Reprend après pause() (ex. enregistrement refusé : le verrou est toujours détenu). */
    function reprendre(): void {
        lancer();
    }

    /** Fin de session d'édition (fermeture, démontage) : tout remettre à zéro. */
    function arreter(): void {
        pause();
        idDossier = null;
        verrouPerdu.value = null;
    }

    return { verrouPerdu: readonly(verrouPerdu), demarrer, pause, reprendre, arreter };
}
