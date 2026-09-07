<!-- resources/js/components/livraison/campagnes/BenevoleDisponibiliteQueue.vue -->
<!--
    Suivi des réponses de disponibilité bénévole — voir le prompt du
    05/09/2026 §1.3 : "In the same fashion as Suivi des contacts I can see
    who responded and who didn't. I can also manually change response if
    needed." Même architecture que ContactsQueue.vue (filtre + table +
    action manuelle), sur BenevoleDisponibilite plutôt que Livraison.
    Le bouton "Notifier bénévole" (CampagneDetail.vue jusqu'ici) vit
    désormais ici.
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue';
import { useToast } from '@amana/shared-ui';
import { apiGet, apiPost, buildQuery } from '../shared/api';
import { CRENEAUX, CRENEAU_LIBELLES, type Campagne, type CampagneJournee, type Creneau } from '../shared/types';

interface LigneBenevole {
    id_personne: number;
    nom: string;
    prenom: string;
    telephone: string | null;
    email: string | null;
    statut: 'non_confirme' | 'confirme';
    vehicule_confirme: boolean;
    coverage_confirmee: boolean;
    coverage_notes: string | null;
    creneaux: Creneau[];
}

const toast = useToast();

const el = document.getElementById('vue-livraison-benevole-disponibilite')!;
const campagne = ref<Campagne>(JSON.parse(el.dataset.campagne ?? '{}'));
const queueUrl = el.dataset.queueUrl ?? '';
const mettreAJourUrlTemplate = el.dataset.mettreAJourUrlTemplate ?? '';
const notifierBenevolesUrl = el.dataset.notifierBenevolesUrl ?? '';

function urlMettreAJour(idPersonne: number): string {
    return mettreAJourUrlTemplate.replace('__ID__', String(idPersonne));
}

const journees = computed<CampagneJournee[]>(() => campagne.value.journees ?? []);
const idJourneeSelectionnee = ref<number | ''>(journees.value[0]?.id ?? '');

const filtreStatut = ref<'' | 'confirme' | 'non_confirme'>('');
const recherche = ref('');

const lignes = ref<LigneBenevole[]>([]);
const chargement = ref(true);
const erreur = ref(false);

async function chargerFile() {
    chargement.value = true;
    erreur.value = false;

    const resultat = await apiGet<{ data: LigneBenevole[]; total: number; id_campagne_journee: number }>(
        queueUrl + buildQuery({
            id_campagne_journee: idJourneeSelectionnee.value,
            statut: filtreStatut.value || undefined,
            recherche: recherche.value || undefined,
        }),
    );
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = true;
        return;
    }

    lignes.value = resultat.data.data;
    if (!idJourneeSelectionnee.value) idJourneeSelectionnee.value = resultat.data.id_campagne_journee;
}

// ── Édition manuelle (05/09/2026, prompt §1.3) ────────────────────────────
const editionOuverte = reactive<Record<number, boolean>>({});
const formulaires = reactive<Record<number, { vehicule_confirme: boolean; coverage_confirmee: boolean; creneaux: Creneau[] }>>({});
const enregistrementEnCours = reactive<Record<number, boolean>>({});

function ouvrirEdition(ligne: LigneBenevole) {
    formulaires[ligne.id_personne] = {
        vehicule_confirme: ligne.vehicule_confirme,
        coverage_confirmee: ligne.coverage_confirmee,
        creneaux: [...ligne.creneaux],
    };
    editionOuverte[ligne.id_personne] = true;
}

function toggleCreneauEdition(idPersonne: number, creneau: Creneau) {
    const f = formulaires[idPersonne];
    const index = f.creneaux.indexOf(creneau);
    if (index === -1) f.creneaux.push(creneau);
    else f.creneaux.splice(index, 1);
}

async function enregistrerConfirme(ligne: LigneBenevole) {
    const f = formulaires[ligne.id_personne];
    enregistrementEnCours[ligne.id_personne] = true;

    const resultat = await apiPost<{ success: boolean }>(urlMettreAJour(ligne.id_personne), {
        id_campagne_journee: idJourneeSelectionnee.value,
        statut: 'confirme',
        vehicule_confirme: f.vehicule_confirme,
        coverage_confirmee: f.coverage_confirmee,
        creneaux: f.creneaux,
    });

    enregistrementEnCours[ligne.id_personne] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Réponse mise à jour.');
    editionOuverte[ligne.id_personne] = false;
    chargerFile();
}

async function marquerNonConfirme(ligne: LigneBenevole) {
    enregistrementEnCours[ligne.id_personne] = true;
    const resultat = await apiPost<{ success: boolean }>(urlMettreAJour(ligne.id_personne), {
        id_campagne_journee: idJourneeSelectionnee.value,
        statut: 'non_confirme',
    });
    enregistrementEnCours[ligne.id_personne] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Marqué non confirmé.');
    chargerFile();
}

// ── Notifier les bénévoles (déplacé depuis CampagneDetail.vue, prompt §1.3) ─
const chargementNotif = ref(false);
const resultatNotif = ref<{ envoyes: number; echecs: number } | null>(null);

async function notifierBenevoles() {
    chargementNotif.value = true;
    resultatNotif.value = null;

    const resultat = await apiPost<{ envoyes: number; echecs: number }>(notifierBenevolesUrl);
    chargementNotif.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultatNotif.value = resultat.data;
    toast.success(`Email envoyé à ${resultat.data.envoyes} bénévole(s).`);
    chargerFile();
}

onMounted(chargerFile);
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h1 class="font-heading text-xl font-semibold text-ink">Suivi des bénévoles</h1>
            <button type="button" :disabled="chargementNotif" @click="notifierBenevoles"
                class="min-h-[2.25rem] text-[13px] px-4 py-2 rounded-lg bg-emerald-600 text-white disabled:opacity-60">
                📧 {{ chargementNotif ? 'Envoi…' : 'Notifier les bénévoles' }}
            </button>
        </div>
        <p v-if="resultatNotif" class="text-[13px] text-ink-muted mb-4">
            {{ resultatNotif.envoyes }} email(s) envoyé(s), {{ resultatNotif.echecs }} échec(s).
        </p>

        <div class="flex flex-wrap items-end gap-3 mb-4">
            <div v-if="journees.length > 1">
                <label class="block text-[12px] text-ink-muted mb-1">Journée</label>
                <select v-model="idJourneeSelectionnee" @change="chargerFile"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option v-for="j in journees" :key="j.id" :value="j.id">{{ j.label ?? j.date }}</option>
                </select>
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Statut</label>
                <select v-model="filtreStatut" @change="chargerFile"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option value="">Tous</option>
                    <option value="confirme">Confirmé</option>
                    <option value="non_confirme">Pas encore répondu</option>
                </select>
            </div>
            <div class="flex-1 min-w-[10rem]">
                <label class="block text-[12px] text-ink-muted mb-1">Recherche</label>
                <input v-model="recherche" @change="chargerFile" type="text" placeholder="Nom du bénévole…"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
            </div>
        </div>

        <p v-if="chargement" class="text-[14px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[14px] text-rose-600">Impossible de charger la liste des bénévoles.</p>
        <p v-else-if="lignes.length === 0" class="text-[14px] text-ink-muted">Aucun bénévole ne correspond à ces filtres.</p>

        <div v-else class="space-y-2">
            <div v-for="ligne in lignes" :key="ligne.id_personne" class="bg-surface border border-surface-border rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[14px] font-medium text-ink">{{ ligne.prenom }} {{ ligne.nom }}</p>
                        <p class="text-[12.5px] text-ink-muted">{{ ligne.telephone || '—' }} · {{ ligne.email || "pas d'email" }}</p>
                    </div>
                    <span class="text-[11.5px] font-medium px-2 py-0.5 rounded-full shrink-0"
                        :class="ligne.statut === 'confirme' ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-ink-muted'">
                        {{ ligne.statut === 'confirme' ? 'Confirmé' : 'Pas encore répondu' }}
                    </span>
                </div>

                <p v-if="ligne.statut === 'confirme'" class="text-[12.5px] text-ink-muted mt-2">
                    Créneaux : {{ ligne.creneaux.map((c) => CRENEAU_LIBELLES[c]).join(', ') || '—' }}
                    <span v-if="ligne.vehicule_confirme"> · véhicule confirmé</span>
                </p>

                <div class="flex gap-2 mt-3">
                    <button type="button" @click="ouvrirEdition(ligne)"
                        class="min-h-[2rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50">
                        Modifier la réponse
                    </button>
                    <button v-if="ligne.statut === 'confirme'" type="button" :disabled="enregistrementEnCours[ligne.id_personne]"
                        @click="marquerNonConfirme(ligne)"
                        class="min-h-[2rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 disabled:opacity-60">
                        Marquer non confirmé
                    </button>
                </div>

                <div v-if="editionOuverte[ligne.id_personne]" class="mt-3 bg-stone-50 rounded-lg p-3 space-y-2">
                    <label class="flex items-center gap-2 text-[12.5px] text-ink-muted">
                        <input type="checkbox" v-model="formulaires[ligne.id_personne].vehicule_confirme" class="w-4 h-4 accent-accent">
                        Véhicule confirmé
                    </label>
                    <label class="flex items-center gap-2 text-[12.5px] text-ink-muted">
                        <input type="checkbox" v-model="formulaires[ligne.id_personne].coverage_confirmee" class="w-4 h-4 accent-accent">
                        Couverture confirmée
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                        <label v-for="creneau in CRENEAUX" :key="creneau"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none has-[:checked]:border-accent has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" :checked="formulaires[ligne.id_personne].creneaux.includes(creneau)"
                                @change="toggleCreneauEdition(ligne.id_personne, creneau)" class="w-3.5 h-3.5 accent-accent">
                            {{ CRENEAU_LIBELLES[creneau] }}
                        </label>
                    </div>
                    <button type="button" :disabled="enregistrementEnCours[ligne.id_personne]" @click="enregistrerConfirme(ligne)"
                        class="min-h-[2rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60">
                        Enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
