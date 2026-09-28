// resources/js/components/livraison/shared/useFormulaireCreneaux.ts
//
// Extrait le 10/09/2026 (Section A4 du refactor) de ContactsQueue.vue et
// BenevoleDisponibiliteQueue.vue, qui réimplémentaient chacun
// indépendamment le même patron : une ligne repliée par défaut, un clic
// révèle un mini-formulaire de sélection de créneaux (regroupés matin/
// après-midi, avec case tout/rien par groupe + une case globale — voir
// le prompt du 05/09/2026 §2.8 puis 09/09/2026 §5 pour la version
// bénévole). Repris à l'identique ici, RoutesPanel.vue/BuildRouteFlow.vue
// NE sont PAS concernés : ils n'ont jamais implémenté ce même patron
// (RoutesPanel.vue replie ses lignes via <details> natif, sans créneaux ;
// BuildRouteFlow.vue n'a aucun repli par ligne), voir la discussion du
// 10/09/2026 avant ce refactor.
//
// Volontairement limité au strict commun aux deux appelants (ouverture +
// créneaux + les 4 fonctions de bascule) : les champs d'état de requête
// (envoiEnCours/erreurs côté Contacts, enregistrementEnCours côté
// Bénévole) restent locaux à chaque composant, leurs formes divergent
// (Contacts n'a qu'un seul type d'action, Bénévole distingue
// ouvrir/enregistrer) et les mélanger ici aurait juste déplacé la
// duplication plutôt que de la supprimer.

import { reactive } from 'vue';
import { CRENEAUX_APRES_MIDI, CRENEAUX_MATIN, type Creneau } from './types';

interface EtatFormulaireCreneaux {
    ouvert: boolean;
    creneaux: Creneau[];
}

export function useFormulaireCreneaux() {
    const formulaires = reactive<Record<number, EtatFormulaireCreneaux>>({});

    function formulaire(id: number): EtatFormulaireCreneaux {
        if (!formulaires[id]) {
            formulaires[id] = { ouvert: false, creneaux: [] };
        }
        return formulaires[id];
    }

    /** Ouvre la ligne — `creneauxInitiaux` pré-remplit la sélection (voir
     *  BenevoleDisponibiliteQueue.vue, qui préremplit depuis les
     *  disponibilités déjà enregistrées ; ContactsQueue.vue n'en a pas
     *  besoin, une nouvelle confirmation part toujours vide). */
    function ouvrir(id: number, creneauxInitiaux: Creneau[] = []) {
        formulaires[id] = { ouvert: true, creneaux: [...creneauxInitiaux] };
    }

    function basculerOuverture(id: number) {
        formulaire(id).ouvert = !formulaire(id).ouvert;
    }

    function fermer(id: number) {
        formulaire(id).ouvert = false;
    }

    function toggleCreneau(id: number, creneau: Creneau) {
        const f = formulaire(id);
        const index = f.creneaux.indexOf(creneau);
        if (index === -1) f.creneaux.push(creneau);
        else f.creneaux.splice(index, 1);
    }

    function groupeToutCoche(id: number, groupe: Creneau[]): boolean {
        return groupe.every((c) => formulaire(id).creneaux.includes(c));
    }

    function toggleGroupe(id: number, groupe: Creneau[]) {
        const f = formulaire(id);
        if (groupeToutCoche(id, groupe)) {
            f.creneaux = f.creneaux.filter((c) => !groupe.includes(c));
        } else {
            f.creneaux = [...new Set([...f.creneaux, ...groupe])];
        }
    }

    function toggleTout(id: number) {
        const toutesCoches = groupeToutCoche(id, CRENEAUX_MATIN) && groupeToutCoche(id, CRENEAUX_APRES_MIDI);
        formulaire(id).creneaux = toutesCoches ? [] : [...CRENEAUX_MATIN, ...CRENEAUX_APRES_MIDI];
    }

    return {
        formulaires,
        formulaire,
        ouvrir,
        fermer,
        basculerOuverture,
        toggleCreneau,
        groupeToutCoche,
        toggleGroupe,
        toggleTout,
    };
}
