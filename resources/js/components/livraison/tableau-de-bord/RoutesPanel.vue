<!-- resources/js/components/livraison/tableau-de-bord/RoutesPanel.vue -->
<!--
    Tournées d'une campagne — remplace les inputs "ID livraison à
    ajouter" / "Nvl bénévole" / "Nvl véhicule" bruts de la version
    placeholder par le picker livraison sourcé sur non-couvertes (voir
    ShortfallPanel.vue, même liste) et PersonPicker pour la réassignation ;
    remplace chaque alert() par Toast + ConfirmDialog pour retirer/scinder/
    supprimer (destructifs).

    Révisé le 09/09/2026 (prompt de cette date §5.2) :
      - VehiculePicker RETIRÉ de la réassignation (§5.2.1 : "driver info
        already includes a vehicule") — le picker bénévole ne propose que
        des bénévoles ayant déclaré un véhicule (avec-vehicule sur
        PersonPicker), le véhicule est dérivé de son profil ;
      - bouton "Supprimer" AJOUTÉ (§5.2.2 : le endpoint routes.supprimer
        existait côté serveur mais n'était wiré à aucun bouton ici) — passe
        désormais la tournée en statut 'annulee' (soft-cancel, voir
        RouteMutationService::supprimer()) plutôt que de la faire
        disparaître ;
      - pastille de statut déplacée en haut à droite de chaque tournée,
        agrandie (§5.2.1bis, même traitement que Contacts/Packaging) ;
      - chaque tournée est désormais une ligne repliable (§5.2.3) :
        repliée = ID, bénévole, créneau, avancement, pastille ; dépliée =
        table des familles avec statut modifiable manuellement (nouvel
        endpoint changerStatutEtape(), pour couvrir "in case driver does
        not [update status]").

    Réordonnancement des étapes : toujours PAS implémenté (aucun endpoint
    ordre, voir commentaire d'origine) — liste affichée dans l'ordre reçu
    (celui du TSP), lecture seule côté ordre.

    Après toute mutation (ajouter/retirer/réassigner/scinder/supprimer/
    changer un statut d'étape), on émet 'changed' pour que LiveBoard.vue
    recharge la liste complète plutôt que de fusionner la réponse
    localement : RouteMutationService renvoie le modèle via ->fresh() SANS
    les relations (benevole/vehiculeType/etapes absents du JSON de
    réponse), un refetch est donc la seule façon fiable d'obtenir l'état à
    jour.
-->
<script setup lang="ts">
import { reactive } from 'vue';
import { useToast, useConfirm } from '@amana/shared-ui';
import { apiPost, apiDelete } from '../shared/api';
import PersonPicker from '../shared/PersonPicker.vue';
import { LIBELLES_STATUT_ETAPE, LIBELLES_STATUT_ROUTE, STATUTS_ETAPE, type Etape, type Livraison, type PersonneResume, type RouteLivraison, type StatutEtape, type StatutRoute } from '../shared/types';

const props = defineProps<{
    routes: RouteLivraison[];
    chargement: boolean;
    erreur: boolean;
    nonCouvertes: Livraison[];
    urlAjouter: string;
    urlRetirer: string;
    urlReassigner: string;
    urlDiviser: string;
    urlSupprimer: string;
    urlEtapeStatut: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();
const confirmDialog = useConfirm();

// Pastilles de statut tournée (09/09/2026, prompt §5.2.1bis) — même palette
// que les autres écrans (émeraude = terminé/positif, ambre = en cours,
// stone = neutre/à venir, rose = annulé).
const STYLES_STATUT_ROUTE: Record<StatutRoute, string> = {
    planifiee: 'bg-stone-100 text-ink-muted',
    chargement: 'bg-amber-100 text-amber-700',
    charge: 'bg-amber-100 text-amber-700',
    en_cours: 'bg-sky-100 text-sky-700',
    livraisons_terminees: 'bg-emerald-100 text-emerald-700',
    terminee: 'bg-emerald-100 text-emerald-700',
    packaging_annule: 'bg-rose-100 text-rose-700',
    annulee: 'bg-rose-100 text-rose-700',
};

const STYLES_STATUT_ETAPE: Record<StatutEtape, string> = {
    en_attente: 'bg-stone-100 text-ink-muted',
    en_cours: 'bg-sky-100 text-sky-700',
    livree: 'bg-emerald-100 text-emerald-700',
    ignoree: 'bg-rose-100 text-rose-700',
};

interface EtatRoute {
    idLivraisonAAjouter: string;
    benevoleReassigne: PersonneResume | null;
    ajoutEnCours: boolean;
    reassignationEnCours: boolean;
    divisionEnCours: boolean;
    suppressionEnCours: boolean;
    retraitEnCours: Record<number, boolean>;
    statutEnCours: Record<number, boolean>;
    // Repliée par défaut (09/09/2026, prompt §5.2.3) — même patron
    // <details>/<summary> que FamilleFilterPanel.vue.
    ouverte: boolean;
}

const etats = reactive<Record<number, EtatRoute>>({});

function etat(routeId: number): EtatRoute {
    if (!etats[routeId]) {
        etats[routeId] = {
            idLivraisonAAjouter: '',
            benevoleReassigne: null,
            ajoutEnCours: false,
            reassignationEnCours: false,
            divisionEnCours: false,
            suppressionEnCours: false,
            retraitEnCours: {},
            statutEnCours: {},
            ouverte: false,
        };
    }
    return etats[routeId];
}

/** Étapes avec une livraison associée — un "retour QG" n'est pas une famille (voir Etape). */
function etapesFamilles(route: RouteLivraison): Etape[] {
    return route.etapes.filter((e) => e.livraison !== null);
}

/** "3/5" — avancement affiché en résumé de la ligne repliée (prompt §5.2.3). */
function avancement(route: RouteLivraison): string {
    const familles = etapesFamilles(route);
    const traitees = familles.filter((e) => e.statut === 'livree' || e.statut === 'ignoree').length;
    return `${traitees}/${familles.length}`;
}

async function ajouter(route: RouteLivraison) {
    const e = etat(route.id);
    const idLivraison = parseInt(e.idLivraisonAAjouter, 10);
    if (!idLivraison) return;

    e.ajoutEnCours = true;
    const resultat = await apiPost<{ success: boolean }>(props.urlAjouter.replace('__ID__', String(route.id)), {
        id_livraison: idLivraison,
    });
    e.ajoutEnCours = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    e.idLivraisonAAjouter = '';
    toast.success('Livraison ajoutée à la tournée.');
    emit('changed');
}

async function retirer(route: RouteLivraison, etapeId: number, nomFamille: string) {
    const confirmed = await confirmDialog.ask({
        message: `Retirer ${nomFamille} de cette tournée ?`,
        danger: true,
    });
    if (!confirmed) return;

    const e = etat(route.id);
    e.retraitEnCours[etapeId] = true;
    const resultat = await apiDelete<{ success: boolean }>(
        props.urlRetirer.replace('__ID__', String(route.id)).replace('__ETAPE__', String(etapeId)),
    );
    e.retraitEnCours[etapeId] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Retirée de la tournée.');
    emit('changed');
}

async function reassigner(route: RouteLivraison) {
    const e = etat(route.id);
    // id_vehicule_type dérivé du profil du bénévole choisi (09/09/2026,
    // prompt §5.2.1) — PersonPicker filtre déjà avec avec-vehicule, donc
    // ce champ ne devrait jamais être vide ici sauf réponse inattendue du
    // picker.
    if (!e.benevoleReassigne?.id_vehicule_type) {
        toast.error('Choisissez un bénévole ayant déclaré un véhicule.');
        return;
    }

    e.reassignationEnCours = true;
    const resultat = await apiPost<{ success: boolean }>(props.urlReassigner.replace('__ID__', String(route.id)), {
        id_benevole: e.benevoleReassigne.id,
        id_vehicule_type: e.benevoleReassigne.id_vehicule_type,
    });
    e.reassignationEnCours = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    e.benevoleReassigne = null;
    toast.success('Tournée réassignée.');
    emit('changed');
}

async function diviser(route: RouteLivraison) {
    const confirmed = await confirmDialog.ask({
        title: 'Scinder cette tournée',
        message: 'La tournée va être divisée en deux tournées distinctes. Continuer ?',
        confirmLabel: 'Scinder',
        danger: true,
    });
    if (!confirmed) return;

    const e = etat(route.id);
    e.divisionEnCours = true;
    const resultat = await apiPost<{ success: boolean }>(props.urlDiviser.replace('__ID__', String(route.id)));
    e.divisionEnCours = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Tournée scindée en deux.');
    emit('changed');
}

/**
 * Supprimer = soft-cancel depuis le 09/09/2026 (prompt de cette date
 * §5.2.2) — la tournée reste affichée avec la pastille "Annulée" (voir
 * RouteMutationService::supprimer()), bouton auparavant absent de cet
 * écran bien que l'endpoint existât déjà côté serveur.
 */
async function supprimer(route: RouteLivraison) {
    const confirmed = await confirmDialog.ask({
        title: 'Supprimer cette tournée',
        message: "La tournée sera annulée et ses familles repasseront non affectées. Elle restera visible avec le statut \"Annulée\". Continuer ?",
        confirmLabel: 'Supprimer',
        danger: true,
    });
    if (!confirmed) return;

    const e = etat(route.id);
    e.suppressionEnCours = true;
    const resultat = await apiDelete<{ success: boolean }>(props.urlSupprimer.replace('__ID__', String(route.id)));
    e.suppressionEnCours = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Tournée supprimée.');
    emit('changed');
}

/**
 * Override manuel du statut d'un arrêt (09/09/2026, prompt §5.2.3 : "User
 * needs to be able to manually change these in case driver does not") —
 * voir LiveBoardController::changerStatutEtape().
 */
async function changerStatutEtape(route: RouteLivraison, etape: Etape, statut: StatutEtape) {
    if (etape.statut === statut) return;

    const e = etat(route.id);
    e.statutEnCours[etape.id] = true;
    const resultat = await apiPost<{ success: boolean }>(
        props.urlEtapeStatut.replace('__ID__', String(route.id)).replace('__ETAPE__', String(etape.id)),
        { statut },
    );
    e.statutEnCours[etape.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    emit('changed');
}
</script>

<template>
    <div class="mb-8">
        <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger les tournées.</p>
        <p v-else-if="routes.length === 0" class="text-[13px] text-ink-muted">Aucune tournée générée.</p>

        <div v-else class="space-y-3">
            <details v-for="route in routes" :key="route.id"
                class="group bg-surface border border-surface-border rounded-xl p-4" :open="etat(route.id).ouverte"
                @toggle="etat(route.id).ouverte = ($event.target as HTMLDetailsElement).open">
                <summary class="cursor-pointer list-none flex items-center justify-between gap-2 select-none -mx-1 -my-1 px-1 py-1 mb-2 rounded-lg hover:bg-surface-2 transition-colors">
                    <span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">
                        <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-90 shrink-0">▸</span>
                        <span class="truncate">
                            #{{ route.id }} — {{ route.benevole?.prenom ?? '' }} {{ route.benevole?.nom ?? '' }}
                            ({{ route.creneau ?? 'imposée' }}) — {{ avancement(route) }}
                        </span>
                    </span>
                    <span class="text-[13px] font-medium px-2.5 py-1 rounded-full shrink-0" :class="STYLES_STATUT_ROUTE[route.statut]">
                        {{ LIBELLES_STATUT_ROUTE[route.statut] }}
                    </span>
                </summary>

                <table class="w-full text-[12.5px] mb-3">
                    <thead>
                        <tr class="text-left text-ink-muted border-b border-surface-border">
                            <th class="py-1.5 font-medium">#</th>
                            <th class="py-1.5 font-medium">Famille</th>
                            <th class="py-1.5 font-medium">Statut</th>
                            <th class="py-1.5 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in route.etapes" :key="e.id" class="border-b border-surface-border last:border-0">
                            <td class="py-1.5 text-ink-muted">{{ e.ordre }}</td>
                            <td class="py-1.5 text-ink">{{ e.livraison ? `${e.livraison.famille.prenom} ${e.livraison.famille.nom}` : 'Retour QG' }}</td>
                            <td class="py-1.5">
                                {{--
                                    Ajouté le 09/09/2026 (prompt de cette
                                    date §3.1) : "the down arrow is
                                    overlapping on the text" — la pastille
                                    (rounded-full px-2 py-0.5) était trop
                                    étroite pour à la fois le texte et la
                                    flèche native du <select>. appearance-none
                                    retire cette flèche native (jamais
                                    réintroduite en CSS ici : la pastille
                                    colorée suffit déjà à signaler qu'il
                                    s'agit d'un contrôle cliquable, comme les
                                    autres pastilles de statut de l'app qui
                                    n'ont pas de flèche du tout) et pr-2
                                    remplace le pr-2 implicite qui laissait
                                    la place à cette flèche.
                                --}}
                                <select v-if="e.livraison" :value="e.statut" :disabled="etat(route.id).statutEnCours[e.id]"
                                    @change="changerStatutEtape(route, e, ($event.target as HTMLSelectElement).value as StatutEtape)"
                                    class="text-[11.5px] font-medium rounded-full pl-2 pr-2 py-0.5 border-0 appearance-none disabled:opacity-60" :class="STYLES_STATUT_ETAPE[e.statut]">
                                    <option v-for="s in STATUTS_ETAPE" :key="s" :value="s">{{ LIBELLES_STATUT_ETAPE[s] }}</option>
                                </select>
                                <span v-else class="text-[11.5px] text-ink-muted">—</span>
                            </td>
                            <td class="py-1.5 text-right">
                                <button v-if="e.livraison" type="button" :disabled="etat(route.id).retraitEnCours[e.id]"
                                    @click="retirer(route, e.id, `${e.livraison.famille.prenom} ${e.livraison.famille.nom}`)"
                                    class="min-h-[1.75rem] shrink-0 text-[11px] text-rose-600 px-2 disabled:opacity-60">
                                    retirer
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="border-t border-surface-border pt-3 space-y-3">
                    <div class="flex flex-col sm:flex-row gap-2">
                        <select v-model="etat(route.id).idLivraisonAAjouter"
                            class="flex-1 rounded-lg border border-surface-border px-2.5 py-1.5 text-[12.5px] min-h-[2.25rem]">
                            <option value="">Ajouter une livraison non couverte…</option>
                            <option v-for="l in nonCouvertes" :key="l.id" :value="l.id">
                                {{ l.famille.prenom }} {{ l.famille.nom }}
                            </option>
                        </select>
                        <button type="button" :disabled="!etat(route.id).idLivraisonAAjouter || etat(route.id).ajoutEnCours"
                            @click="ajouter(route)"
                            class="min-h-[2.25rem] shrink-0 text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                            {{ etat(route.id).ajoutEnCours ? 'Ajout…' : 'Ajouter' }}
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                        <div class="sm:col-span-2">
                            <PersonPicker role="benevole" avec-vehicule placeholder="Nouveau bénévole…" v-model="etat(route.id).benevoleReassigne" />
                        </div>
                        <button type="button" :disabled="etat(route.id).reassignationEnCours" @click="reassigner(route)"
                            class="min-h-[2.25rem] text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                            {{ etat(route.id).reassignationEnCours ? 'Réassignation…' : 'Réassigner' }}
                        </button>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" :disabled="route.statut !== 'planifiee' || etat(route.id).suppressionEnCours" @click="supprimer(route)"
                            class="min-h-[2rem] text-[11px] px-2.5 py-1 rounded-lg border border-rose-200 text-rose-600 disabled:opacity-40 disabled:cursor-not-allowed">
                            {{ etat(route.id).suppressionEnCours ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </details>
        </div>
    </div>
</template>
