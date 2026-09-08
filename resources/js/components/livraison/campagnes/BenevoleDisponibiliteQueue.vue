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
import { CRENEAUX_MATIN, CRENEAUX_APRES_MIDI, CRENEAU_LIBELLES, type Campagne, type CampagneJournee, type Creneau } from '../shared/types';

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
const personneEditUrlTemplate = el.dataset.personneEditUrlTemplate ?? '';

function urlMettreAJour(idPersonne: number): string {
    return mettreAJourUrlTemplate.replace('__ID__', String(idPersonne));
}

function urlModifierInformations(idPersonne: number): string {
    return personneEditUrlTemplate.replace('__ID__', String(idPersonne));
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

// ── Tri + cartes statistiques (08/09/2026, prompt de cette date §5.1/§5.2) ──
// lignes n'est PAS paginée côté serveur (voir BenevoleDisponibiliteController
// ::queue(), 'data' contient déjà tout l'ensemble filtré) — les stats se
// calculent donc directement ici plutôt que via un aller-retour serveur
// séparé comme pour ContactsQueue.vue (qui, lui, pagine réellement sa
// liste).
const lignesTriees = computed(() => [...lignes.value].sort(
    (a, b) => Number(a.statut === 'confirme') - Number(b.statut === 'confirme'),
));

const statsBenevoles = computed(() => ({
    total: lignes.value.length,
    confirme: lignes.value.filter((l) => l.statut === 'confirme').length,
    non_confirme: lignes.value.filter((l) => l.statut === 'non_confirme').length,
}));

// ── Édition manuelle (05/09/2026, prompt §1.3) ────────────────────────────
// Réduit à `creneaux` (07/09/2026, prompt §5.1 : "add two buttons, one
// Modifier informations that opens personnes/{id}/modifier and another
// for Modifier disponibilités that let me choose créneaux") —
// vehicule_confirme/coverage_confirmee ne sont plus édités depuis ce
// panneau : ce n'étaient que des booléens "confirmé inchangé", pas les
// vraies valeurs (id_vehicule_type/secteurs vivent sur BenevoleProfil,
// voir resources/views/personnes/form.blade.php) — éditer la vraie
// valeur se fait maintenant via "Modifier informations". Toujours
// affichés en lecture seule sur la ligne (voir le template) pour garder
// le contexte visible sans avoir à ouvrir la fiche personne. Voir
// BenevoleDisponibiliteService::confirmer(), corrigé dans ce même patch
// pour ne plus écraser ces deux champs à `false` quand ils ne sont plus
// envoyés.
const editionOuverte = reactive<Record<number, boolean>>({});
const formulaires = reactive<Record<number, { creneaux: Creneau[] }>>({});
const enregistrementEnCours = reactive<Record<number, boolean>>({});

function ouvrirEdition(ligne: LigneBenevole) {
    formulaires[ligne.id_personne] = {
        creneaux: [...ligne.creneaux],
    };
    editionOuverte[ligne.id_personne] = true;
}

function groupeToutCoche(idPersonne: number, groupe: Creneau[]): boolean {
    return groupe.every((c) => formulaires[idPersonne]?.creneaux.includes(c));
}

function toggleGroupeEdition(idPersonne: number, groupe: Creneau[]) {
    const f = formulaires[idPersonne];
    if (groupeToutCoche(idPersonne, groupe)) f.creneaux = f.creneaux.filter((c) => !groupe.includes(c));
    else f.creneaux = [...new Set([...f.creneaux, ...groupe])];
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

        <!--
            Cartes statistiques (08/09/2026, prompt de cette date §5.2).
        -->
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="bg-surface border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Bénévoles</p>
                <p class="text-[20px] font-semibold text-ink">{{ statsBenevoles.total }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Confirmés</p>
                <p class="text-[20px] font-semibold text-emerald-700">{{ statsBenevoles.confirme }}</p>
            </div>
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Non confirmés</p>
                <p class="text-[20px] font-semibold text-ink">{{ statsBenevoles.non_confirme }}</p>
            </div>
        </div>

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

        <!--
            Triée confirmés-en-bas (08/09/2026, prompt de cette date §5.1)
            — voir lignesTriees ci-dessus.
        -->
        <div v-else class="space-y-2">
            <div v-for="ligne in lignesTriees" :key="ligne.id_personne" class="bg-surface border border-surface-border rounded-xl p-4">
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
                    <span v-if="ligne.coverage_confirmee"> · couverture confirmée</span>
                </p>

                <!--
                    Deux boutons distincts (07/09/2026, prompt §5.1) —
                    remplace l'unique "Modifier la réponse" : "Modifier
                    informations" ouvre la fiche personne (véhicule réel/
                    secteurs couverts, voir personnes/form.blade.php),
                    "Modifier disponibilités" ne touche qu'aux créneaux
                    (voir formulaires ci-dessus).
                -->
                <div class="flex flex-wrap gap-2 mt-3">
                    <a :href="urlModifierInformations(ligne.id_personne)"
                        class="min-h-[2rem] inline-flex items-center text-[12.5px] px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:opacity-90">
                        Modifier informations
                    </a>
                    <button type="button" @click="ouvrirEdition(ligne)"
                        class="min-h-[2rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50">
                        Modifier disponibilités
                    </button>
                    <button v-if="ligne.statut === 'confirme'" type="button" :disabled="enregistrementEnCours[ligne.id_personne]"
                        @click="marquerNonConfirme(ligne)"
                        class="min-h-[2rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 disabled:opacity-60">
                        Marquer non confirmé
                    </button>
                </div>

                <!--
                    Regroupement matin/après-midi (07/09/2026, prompt
                    §5.2 : "use the same layout as with families") — même
                    structure que ContactsQueue.vue.
                -->
                <div v-if="editionOuverte[ligne.id_personne]" class="mt-3 bg-stone-50 rounded-lg p-3 space-y-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="border border-ink-faint rounded-lg p-2">
                            <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                <input type="checkbox" :checked="groupeToutCoche(ligne.id_personne, CRENEAUX_MATIN)"
                                    @change="toggleGroupeEdition(ligne.id_personne, CRENEAUX_MATIN)" class="w-3.5 h-3.5 accent-accent">
                                Matin
                            </label>
                            <div class="flex flex-wrap gap-1.5">
                                <label v-for="creneau in CRENEAUX_MATIN" :key="creneau"
                                    class="flex items-center gap-1.5 px-2.5 py-1.5 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none has-[:checked]:border-accent has-[:checked]:text-ink has-[:checked]:font-semibold">
                                    <input type="checkbox" :checked="formulaires[ligne.id_personne].creneaux.includes(creneau)"
                                        @change="toggleCreneauEdition(ligne.id_personne, creneau)" class="w-3.5 h-3.5 accent-accent">
                                    {{ CRENEAU_LIBELLES[creneau] }}
                                </label>
                            </div>
                        </div>
                        <div class="border border-ink-faint rounded-lg p-2">
                            <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                <input type="checkbox" :checked="groupeToutCoche(ligne.id_personne, CRENEAUX_APRES_MIDI)"
                                    @change="toggleGroupeEdition(ligne.id_personne, CRENEAUX_APRES_MIDI)" class="w-3.5 h-3.5 accent-accent">
                                Après-midi
                            </label>
                            <div class="flex flex-wrap gap-1.5">
                                <label v-for="creneau in CRENEAUX_APRES_MIDI" :key="creneau"
                                    class="flex items-center gap-1.5 px-2.5 py-1.5 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none has-[:checked]:border-accent has-[:checked]:text-ink has-[:checked]:font-semibold">
                                    <input type="checkbox" :checked="formulaires[ligne.id_personne].creneaux.includes(creneau)"
                                        @change="toggleCreneauEdition(ligne.id_personne, creneau)" class="w-3.5 h-3.5 accent-accent">
                                    {{ CRENEAU_LIBELLES[creneau] }}
                                </label>
                            </div>
                        </div>
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
