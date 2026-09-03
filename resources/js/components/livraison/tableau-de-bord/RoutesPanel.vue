<!-- resources/js/components/livraison/tableau-de-bord/RoutesPanel.vue -->
<!--
    Tournées d'une campagne — remplace les inputs "ID livraison à
    ajouter" / "Nvl bénévole" / "Nvl véhicule" bruts de la version
    placeholder par le picker livraison sourcé sur non-couvertes (voir
    ShortfallPanel.vue, même liste) et PersonPicker/VehiculePicker pour la
    réassignation ; remplace chaque alert() par Toast + ConfirmDialog pour
    retirer/scinder (destructif).

    Réordonnancement des étapes : PAS implémenté ici. Aucun endpoint
    n'existe pour muter etapes.ordre (voir RouteMutationService — seuls
    ajouterLivraison/retirerLivraison/reassigner/diviser/
    construirePersonnalisee existent, aucun "réordonner"), donc la liste
    des arrêts est affichée dans l'ordre reçu (celui du TSP), en lecture
    seule côté ordre. Ajouter un endpoint dédié serait la même décision
    que les pickers personne/véhicule (Patch 1) — à confirmer avant de
    l'ajouter plutôt que fabriqué ici en silence.

    Après toute mutation (ajouter/retirer/réassigner/scinder), on émet
    'changed' pour que LiveBoard.vue recharge la liste complète plutôt que
    de fusionner la réponse localement : RouteMutationService renvoie le
    modèle via ->fresh() SANS les relations (benevole/vehiculeType/etapes
    absents du JSON de réponse), un refetch est donc la seule façon fiable
    d'obtenir l'état à jour.
-->
<script setup lang="ts">
import { reactive } from 'vue';
import { useToast, useConfirm } from '@amana/shared-ui';
import { apiPost, apiDelete } from '../shared/api';
import PersonPicker from '../shared/PersonPicker.vue';
import VehiculePicker from '../shared/VehiculePicker.vue';
import type { Livraison, PersonneResume, RouteLivraison } from '../shared/types';

const props = defineProps<{
    routes: RouteLivraison[];
    chargement: boolean;
    erreur: boolean;
    nonCouvertes: Livraison[];
    urlAjouter: string;
    urlRetirer: string;
    urlReassigner: string;
    urlDiviser: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();
const confirmDialog = useConfirm();

interface EtatRoute {
    idLivraisonAAjouter: string;
    benevoleReassigne: PersonneResume | null;
    idVehiculeReassigne: number | null;
    ajoutEnCours: boolean;
    reassignationEnCours: boolean;
    divisionEnCours: boolean;
    retraitEnCours: Record<number, boolean>;
}

const etats = reactive<Record<number, EtatRoute>>({});

function etat(routeId: number): EtatRoute {
    if (!etats[routeId]) {
        etats[routeId] = {
            idLivraisonAAjouter: '',
            benevoleReassigne: null,
            idVehiculeReassigne: null,
            ajoutEnCours: false,
            reassignationEnCours: false,
            divisionEnCours: false,
            retraitEnCours: {},
        };
    }
    return etats[routeId];
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
    if (!e.benevoleReassigne || !e.idVehiculeReassigne) {
        toast.error('Choisissez un bénévole et un véhicule.');
        return;
    }

    e.reassignationEnCours = true;
    const resultat = await apiPost<{ success: boolean }>(props.urlReassigner.replace('__ID__', String(route.id)), {
        id_benevole: e.benevoleReassigne.id,
        id_vehicule_type: e.idVehiculeReassigne,
    });
    e.reassignationEnCours = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    e.benevoleReassigne = null;
    e.idVehiculeReassigne = null;
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
</script>

<template>
    <div class="mb-8">
        <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger les tournées.</p>
        <p v-else-if="routes.length === 0" class="text-[13px] text-ink-muted">Aucune tournée générée.</p>

        <div v-else class="space-y-3">
            <div v-for="route in routes" :key="route.id" class="bg-surface border border-surface-border rounded-xl p-4">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="text-[14px] font-medium text-ink">
                        #{{ route.id }} — {{ route.benevole?.prenom ?? '' }} {{ route.benevole?.nom ?? '' }}
                        ({{ route.creneau ?? 'imposée' }}, {{ route.statut }})
                    </span>
                    <button type="button" :disabled="etat(route.id).divisionEnCours" @click="diviser(route)"
                        class="min-h-[2rem] shrink-0 text-[11px] px-2.5 py-1 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                        {{ etat(route.id).divisionEnCours ? 'Scission…' : 'Scinder' }}
                    </button>
                </div>

                <ol class="text-[12.5px] text-ink-muted space-y-1 mb-3">
                    <li v-for="e in route.etapes" :key="e.id" class="flex items-center justify-between gap-2">
                        <span>
                            {{ e.ordre }}.
                            {{ e.livraison ? `${e.livraison.famille.prenom} ${e.livraison.famille.nom}` : 'Retour QG' }}
                            ({{ e.statut }})
                        </span>
                        <button v-if="e.livraison" type="button" :disabled="etat(route.id).retraitEnCours[e.id]"
                            @click="retirer(route, e.id, `${e.livraison.famille.prenom} ${e.livraison.famille.nom}`)"
                            class="min-h-[1.75rem] shrink-0 text-[11px] text-rose-600 px-2 disabled:opacity-60">
                            retirer
                        </button>
                    </li>
                </ol>

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
                        <PersonPicker role="benevole" placeholder="Nouveau bénévole…" v-model="etat(route.id).benevoleReassigne" />
                        <VehiculePicker v-model="etat(route.id).idVehiculeReassigne" />
                        <button type="button" :disabled="etat(route.id).reassignationEnCours" @click="reassigner(route)"
                            class="min-h-[2.25rem] text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                            {{ etat(route.id).reassignationEnCours ? 'Réassignation…' : 'Réassigner' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
