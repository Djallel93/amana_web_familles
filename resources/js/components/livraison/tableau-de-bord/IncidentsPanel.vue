<!-- resources/js/components/livraison/tableau-de-bord/IncidentsPanel.vue -->
<!--
    Incidents ouverts d'une campagne — remplace les alert() de la version
    placeholder par un panneau de résultat visible pour la résolution
    (routes créées / non couvertes, voir ResoudreIncidentResultat) plutôt
    qu'un simple message. Ne peut structurellement recevoir que les types
    benevole_absent/capacite/livraison_ignoree : chargement_termine n'a
    pas de statut (voir RouteIncident::TYPES_SANS_STATUT), donc jamais
    statut='ouvert' — le filtre serveur (incidents(), voir
    LiveBoardController) l'exclut déjà de cette liste avant qu'elle
    n'arrive ici.
-->
<script setup lang="ts">
import { reactive } from 'vue';
import { useToast, useConfirm } from '@amana/shared-ui';
import { apiPost } from '../shared/api';
import type { ResoudreIncidentResultat, RouteIncident } from '../shared/types';

const props = defineProps<{
    incidents: RouteIncident[];
    chargement: boolean;
    erreur: boolean;
    urlResoudre: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();
const confirmDialog = useConfirm();

const enCours = reactive<Record<number, boolean>>({});

const LABELS: Record<string, string> = {
    benevole_absent: 'Bénévole absent',
    capacite: 'Capacité dépassée',
    livraison_ignoree: 'Livraison ignorée',
    chargement_termine: 'Chargement terminé',
};

function urlPour(id: number): string {
    return props.urlResoudre.replace('__ID__', String(id));
}

async function resoudre(incident: RouteIncident) {
    // benevole_absent déclenche un re-cluster côté serveur (voir
    // LiveBoardController::resoudreIncident()) — confirmation avant
    // action pour ce type précis, contrairement à capacite/
    // livraison_ignoree qui se contentent de marquer l'incident résolu
    // sans effet de bord.
    if (incident.type === 'benevole_absent') {
        const confirmed = await confirmDialog.ask({
            title: 'Résoudre cet incident',
            message: 'Les livraisons orphelines de cette tournée vont être relancées dans un nouveau cycle de clustering. Continuer ?',
            confirmLabel: 'Résoudre et relancer',
        });
        if (!confirmed) return;
    }

    enCours[incident.id] = true;
    const resultat = await apiPost<ResoudreIncidentResultat & { success: boolean }>(urlPour(incident.id));
    enCours[incident.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    if (resultat.data.routes_creees !== undefined) {
        toast.success(`Résolu. ${resultat.data.routes_creees} nouvelle(s) tournée(s), ${resultat.data.non_couvertes} non couverte(s).`);
    } else {
        toast.success('Incident résolu.');
    }

    emit('changed');
}
</script>

<template>
    <div class="mb-8">
        <h2 class="text-[14px] font-medium text-rose-600 mb-3">⚠ Incidents ouverts</h2>
        <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger les incidents.</p>
        <p v-else-if="incidents.length === 0" class="text-[13px] text-ink-muted">Aucun incident ouvert.</p>
        <div v-else class="space-y-2">
            <div v-for="incident in incidents" :key="incident.id"
                class="bg-rose-50 border border-rose-200 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <span class="text-[13px] text-rose-700">
                    {{ LABELS[incident.type] ?? incident.type }}
                    — tournée #{{ incident.route?.id }}
                    ({{ incident.route?.benevole?.prenom ?? '' }} {{ incident.route?.benevole?.nom ?? '' }})
                </span>
                <button type="button" :disabled="enCours[incident.id]" @click="resoudre(incident)"
                    class="min-h-[2.25rem] shrink-0 text-[12px] px-3 py-1.5 rounded-lg bg-white border border-rose-300 text-rose-700 disabled:opacity-60">
                    {{ enCours[incident.id] ? 'Résolution…' : 'Résoudre' }}
                </button>
            </div>
        </div>
    </div>
</template>
