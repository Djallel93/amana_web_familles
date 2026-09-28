<!-- resources/js/components/familles/FamillesTable.vue -->
<!--
    Port Vue de resources/views/familles/partials/tableau.blade.php —
    Section E4 du refactor (12/09/2026), chunk 3. Porté colonne par
    colonne à l'identique (mêmes classes Tailwind, même logique de
    visibilité/tri) plutôt que redessiné, pour limiter le risque de
    dérive visuelle/comportementale impossible à vérifier ici (pas
    d'app qui tourne dans ce bac à sable, voir la discussion du chunk).

    Constantes dupliquées depuis App\Models\Famille (COLONNES_TABLEAU,
    ETAT_COLORS, ETAT_COLORS_LISTERE, TYPE_PIECE_IDENTITE_LABELS,
    LANGUES, AVATAR_PALETTE) — même pattern déjà utilisé par
    DetailPanel.vue pour LANGUES/COULEURS_ETAT. À garder synchronisées
    manuellement si ces constantes PHP changent, comme c'est déjà le cas
    aujourd'hui pour DetailPanel.vue.

    Tri/pagination : <Link>/router.get() (Inertia) plutôt que des <a
    href="{{ route(...) }}"> classiques — c'est le "vrai" objectif de
    cette section (voir le docblock de FamilleFiltresBar.vue, qui pointe
    justement vers "la migration Inertia.js (Section E)" pour rendre cet
    écran réactif sans rechargement). `currentQuery` (filtres actifs)
    reçu en prop plutôt que lu ici : ce composant ne connaît pas la forme
    exacte du state de filtres, seule la page parente (Familles/Index.vue,
    Familles/Nouvelles.vue) le sait.

    Sélecteur de colonnes : repris de la version Blade tel quel (état
    local, jamais persisté — même décision du 12/08/2026), mais en état
    Vue réactif (`colonnesVisibles`) plutôt qu'un [hidden] manipulé par un
    <script> vanilla — pas de raison de garder l'ancien mécanisme DOM one
    à côté de la réactivité Vue déjà en place partout ailleurs sur cette
    page migrée.

    Pagination : réutilise Paginator.vue (livraison/shared/), déjà conçu
    pour reprendre le même visuel que partials/pagination.blade.php (voir
    son propre docblock) — pas de raison d'en écrire un second pour cette
    page. Son prop `meta` attend une forme imbriquée
    ({data,meta:{...}}), reconstruite ici depuis `familles` (forme plate
    de LengthAwarePaginator) plutôt que de changer la forme attendue par
    Paginator.vue (partagé avec le domaine livraison, hors périmètre de
    cette section).

    Ouverture du panneau détail : window.openFamilleDetail(id), pas un
    emit — DetailPanel.vue expose déjà cette fonction globalement dans
    les DEUX modes de son pont double-mode (voir son propre commentaire),
    donc aucune plomberie supplémentaire n'est nécessaire pour que cette
    page migrée continue de l'ouvrir exactement comme avant.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Paginator from '../livraison/shared/Paginator.vue';

interface QuartierResume {
    id: number;
    nom: string;
}

interface OrganisationResume {
    id: number;
    nom: string;
}

export interface FamilleVerrou {
    par: string | null;
    par_moi: boolean;
    depuis: string;
}

export interface FamilleLigne {
    id: number;
    nom: string;
    prenom: string;
    nombre_foyer: number;
    probleme_traitement: string | null;
    etat_dossier: string;
    // Verrou d'édition EN COURS (Scénario 5, voir FamilleListItemResource) :
    // null = personne ; absent = relation non chargée côté serveur.
    verrou?: FamilleVerrou | null;
    email: string | null;
    telephone_formate: string;
    telephone_bis_formate: string | null;
    adresse_complete: string;
    quartier: QuartierResume | null;
    ville: string | null;
    organisation_origine: OrganisationResume | null;
    organisations: OrganisationResume[];
    nombre_adulte: number;
    nombre_enfant: number;
    criticite: number;
    zakat_el_fitr: boolean;
    sadaqa: boolean;
    est_hotel: boolean;
    etudiant: boolean;
    langue: string;
    type_piece_identite: string | null;
    circonstances: string | null;
    ressentit: string | null;
    specificites: string | null;
    commentaire_dossier: string | null;
    created_at: string | null;
}

interface FamillesPaginees {
    data: FamilleLigne[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
}

const props = defineProps<{
    familles: FamillesPaginees;
    baseUrl: string;
    currentQuery: Record<string, string | number | boolean | number[] | undefined>;
    triActuel: string | null;
    directionActuelle: 'asc' | 'desc';
    videIcone: string;
    videTitre: string;
    aFiltresActifs: boolean;
    videMessageBase: string;
    videMessageFiltre: string;
    videLienReinitialisation: { texte: string; href: string } | null;
}>();

// Reprise à l'identique de Famille::COLONNES_TABLEAU (voir le docblock de
// tableau.blade.php sur la nécessité de garder COLONNES_TRIABLES
// (FamillesController) synchronisée avec les 'triable' ci-dessous —
// inchangée par ce port, toujours vraie côté serveur).
const COLONNES_TABLEAU: Record<string, { label: string; triable: boolean; defaut: boolean }> = {
    id: { label: 'ID', triable: true, defaut: true },
    nom: { label: 'Nom', triable: true, defaut: true },
    statut: { label: 'Statut', triable: true, defaut: true },
    email: { label: 'Email', triable: true, defaut: false },
    telephone: { label: 'Téléphone', triable: true, defaut: true },
    telephone_bis: { label: 'Tél. bis', triable: true, defaut: false },
    adresse: { label: 'Adresse', triable: true, defaut: true },
    quartier: { label: 'Quartier', triable: false, defaut: true },
    ville: { label: 'Ville', triable: false, defaut: true },
    organisation: { label: 'Organisation', triable: false, defaut: false },
    nombre_adulte: { label: 'Adultes', triable: true, defaut: false },
    nombre_enfant: { label: 'Enfants', triable: true, defaut: false },
    criticite: { label: 'Criticité', triable: true, defaut: true },
    eligibilite: { label: 'Éligibilité', triable: true, defaut: true },
    est_hotel: { label: 'Hôtel', triable: true, defaut: false },
    etudiant: { label: 'Étudiant', triable: true, defaut: false },
    langue: { label: 'Langue', triable: true, defaut: false },
    type_piece_identite: { label: 'Pièce identité', triable: true, defaut: false },
    circonstances: { label: 'Circonstances', triable: false, defaut: false },
    ressentit: { label: 'Ressenti', triable: false, defaut: false },
    specificites: { label: 'Spécificités', triable: false, defaut: false },
    commentaire_dossier: { label: 'Commentaire', triable: false, defaut: false },
    created_at: { label: 'Créé le', triable: true, defaut: false },
};

const TYPE_PIECE_IDENTITE_LABELS: Record<string, string> = {
    nationalite: 'Nationalité',
    titre_sejour: 'Titre de séjour',
    demande_asile: "Demande d'asile",
    autre: 'Autre',
};

const LANGUES: Record<string, string> = { fr: 'Français', ar: 'العربية', en: 'English' };

const ETAT_COLORS_LISTERE: Record<string, string> = {
    Recu: 'border-l-stone-400',
    'En cours': 'border-l-sky-400',
    'En attente': 'border-l-amber-400',
    Validé: 'border-l-emerald-500',
    Rejeté: 'border-l-rose-400',
    Archivé: 'border-l-gray-400',
};

const ETAT_COLORS: Record<string, string> = {
    Recu: 'bg-stone-100 text-stone-700 border-stone-300',
    'En cours': 'bg-sky-50 text-sky-700 border-sky-200',
    'En attente': 'bg-amber-50 text-amber-700 border-amber-200',
    Validé: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    Rejeté: 'bg-rose-50 text-rose-700 border-rose-200',
    Archivé: 'bg-gray-100 text-gray-500 border-gray-300',
};

const AVATAR_PALETTE: { bg: string; text: string }[] = [
    { bg: 'bg-sky-100', text: 'text-sky-700' },
    { bg: 'bg-amber-100', text: 'text-amber-700' },
    { bg: 'bg-emerald-100', text: 'text-emerald-700' },
    { bg: 'bg-violet-100', text: 'text-violet-700' },
    { bg: 'bg-rose-100', text: 'text-rose-700' },
    { bg: 'bg-cyan-100', text: 'text-cyan-700' },
];

function avatarStyle(id: number): { bg: string; text: string } {
    return AVATAR_PALETTE[id % AVATAR_PALETTE.length];
}

function initiales(ligne: FamilleLigne): string {
    return (ligne.prenom.slice(0, 1) + ligne.nom.slice(0, 1)).toUpperCase();
}

// État local du sélecteur de colonnes — jamais persisté (voir docblock),
// toujours réinitialisé aux valeurs 'defaut' de COLONNES_TABLEAU au
// (re)montage du composant, exactement comme le comportement Blade
// d'origine au rechargement de page.
// Marqueur "🔒 <nom>" (Scénario 5 du chantier "polling live") — affiché
// pour un verrou d'édition encore valide uniquement (le serveur renvoie
// null dès que le verrou est périmé, voir Famille::verrouFrais()) : c'est ce
// qui distingue un dossier réellement en cours d'édition d'un dossier resté
// à 'En cours' après un plantage de navigateur.
function libelleVerrou(verrou: FamilleVerrou): string {
    return verrou.par_moi ? 'vous' : (verrou.par ?? 'un collègue');
}

function titreVerrou(verrou: FamilleVerrou): string {
    const heure = new Date(verrou.depuis).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    return `En cours de modification par ${verrou.par_moi ? 'vous' : (verrou.par ?? 'un collègue')} depuis ${heure}`;
}

const colonnesVisibles = ref<Record<string, boolean>>(
    Object.fromEntries(Object.entries(COLONNES_TABLEAU).map(([cle, colonne]) => [cle, colonne.defaut])),
);

const metaPagination = computed(() => ({
    current_page: props.familles.current_page,
    last_page: props.familles.last_page,
    from: props.familles.from ?? 0,
    to: props.familles.to ?? 0,
    total: props.familles.total,
    per_page: props.familles.per_page,
}));

function visiter(donnees: Record<string, string | number | boolean | undefined>) {
    router.get(props.baseUrl, { ...props.currentQuery, ...donnees }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function prochaineDirection(cle: string): 'asc' | 'desc' {
    return props.triActuel === cle && props.directionActuelle === 'asc' ? 'desc' : 'asc';
}

function donneesTri(cle: string) {
    return { ...props.currentQuery, tri: cle, direction: prochaineDirection(cle) };
}

function ouvrir(id: number) {
    window.openFamilleDetail?.(id);
}
</script>

<template>
    <div class="hidden md:flex justify-end mb-2">
        <details class="relative">
            <summary class="cursor-pointer list-none px-3 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink-muted text-[12px] font-semibold rounded-md inline-flex items-center gap-1 select-none transition-colors">
                Colonnes <span class="text-[10px]">▾</span>
            </summary>
            <div class="absolute right-0 mt-1 z-20 bg-surface border border-surface-border rounded-md shadow-lg p-1.5 w-48 max-h-80 overflow-y-auto flash-enter">
                <label v-for="(colonne, cle) in COLONNES_TABLEAU" :key="cle"
                    class="flex items-center gap-2 text-[12px] text-ink-muted px-2 py-1.5 hover:bg-surface-2 rounded cursor-pointer select-none">
                    <input type="checkbox" class="w-3.5 h-3.5 accent-accent" v-model="colonnesVisibles[cle]">
                    {{ colonne.label }}
                </label>
            </div>
        </details>
    </div>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        <div v-if="familles.data.length === 0" class="text-center py-16 px-8">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center text-3xl">{{ videIcone }}</div>
            <h3 class="font-heading text-base font-semibold text-ink mb-1.5">{{ videTitre }}</h3>
            <p class="text-ink-muted text-[13.5px] max-w-sm mx-auto">
                <template v-if="aFiltresActifs">
                    {{ videMessageFiltre }}
                    <Link v-if="videLienReinitialisation" :href="videLienReinitialisation.href" class="text-accent hover:underline font-semibold">{{ videLienReinitialisation.texte }}</Link>.
                </template>
                <template v-else>{{ videMessageBase }}</template>
            </p>
        </div>

        <template v-else>
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th v-for="(colonne, cle) in COLONNES_TABLEAU" :key="cle" v-show="colonnesVisibles[cle]"
                                class="sticky top-topbar sm:top-0 z-10 text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                <Link v-if="colonne.triable" :href="baseUrl" :data="donneesTri(String(cle))" preserve-state preserve-scroll replace
                                    class="inline-flex items-center gap-1 text-ink-muted hover:text-ink no-underline">
                                    {{ colonne.label }}
                                    <span v-if="triActuel === cle" class="text-accent normal-case">{{ directionActuelle === 'asc' ? '↑' : '↓' }}</span>
                                </Link>
                                <template v-else>{{ colonne.label }}</template>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ligne in familles.data" :key="ligne.id" @click="ouvrir(ligne.id)"
                            class="border-b border-surface-3 last:border-b-0 border-l-4 hover:bg-surface-2 active:bg-surface-3 transition-colors duration-150 cursor-pointer"
                            :class="[ETAT_COLORS_LISTERE[ligne.etat_dossier] ?? 'border-l-gray-300', ligne.probleme_traitement ? 'bg-rose-50/60' : '']">
                            <td v-show="colonnesVisibles.id" class="px-4 py-2.5 text-ink-faint font-mono text-[12px]">#{{ ligne.id }}</td>
                            <td v-show="colonnesVisibles.nom" class="px-4 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 text-[11px] rounded-full flex items-center justify-center font-bold flex-shrink-0" :class="[avatarStyle(ligne.id).bg, avatarStyle(ligne.id).text]">
                                        {{ initiales(ligne) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-ink">{{ ligne.prenom }} {{ ligne.nom }}</div>
                                        <div class="text-[11.5px] text-ink-muted">{{ ligne.nombre_foyer }} pers.</div>
                                        <span v-if="ligne.verrou" data-verrou class="inline-flex items-center gap-1 mt-0.5 px-1.5 py-0.5 rounded-full text-[10.5px] font-semibold border border-amber-300 bg-amber-50 text-amber-800" :title="titreVerrou(ligne.verrou)">🔒 {{ libelleVerrou(ligne.verrou) }}</span>
                                        <div v-if="ligne.probleme_traitement" class="text-[11px] text-rose-600 font-semibold mt-0.5">⚠️ {{ ligne.probleme_traitement }}</div>
                                    </div>
                                </div>
                            </td>
                            <td v-show="colonnesVisibles.statut" class="px-4 py-2.5">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border" :class="ETAT_COLORS[ligne.etat_dossier] ?? ''">
                                    {{ ligne.etat_dossier }}
                                </span>
                            </td>
                            <td v-show="colonnesVisibles.email" class="px-4 py-2.5 text-ink-muted">{{ ligne.email ?? '—' }}</td>
                            <td v-show="colonnesVisibles.telephone" class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ ligne.telephone_formate }}</td>
                            <td v-show="colonnesVisibles.telephone_bis" class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ ligne.telephone_bis_formate ?? '—' }}</td>
                            <td v-show="colonnesVisibles.adresse" class="px-4 py-2.5 text-ink-muted">{{ ligne.adresse_complete }}</td>
                            <td v-show="colonnesVisibles.quartier" class="px-4 py-2.5 text-ink-muted">{{ ligne.quartier?.nom ?? '—' }}</td>
                            <td v-show="colonnesVisibles.ville" class="px-4 py-2.5 text-ink-muted">{{ ligne.ville ?? '—' }}</td>
                            <td v-show="colonnesVisibles.organisation" class="px-4 py-2.5 text-ink-muted">
                                {{ ligne.organisation_origine?.nom ?? '—' }}
                                <template v-for="organisationRattachee in ligne.organisations" :key="organisationRattachee.id">
                                    <span v-if="!ligne.organisation_origine || organisationRattachee.id !== ligne.organisation_origine.id"
                                        class="inline-flex px-1.5 py-0.5 ml-1 rounded-full bg-accent/10 text-accent-dark text-[10px] font-semibold">{{ organisationRattachee.nom }}</span>
                                </template>
                            </td>
                            <td v-show="colonnesVisibles.nombre_adulte" class="px-4 py-2.5 text-ink-muted text-center">{{ ligne.nombre_adulte }}</td>
                            <td v-show="colonnesVisibles.nombre_enfant" class="px-4 py-2.5 text-ink-muted text-center">{{ ligne.nombre_enfant }}</td>
                            <td v-show="colonnesVisibles.criticite" class="px-4 py-2.5">
                                <div class="flex items-center gap-1" :title="`Criticité ${ligne.criticite}/5`">
                                    <span v-for="i in 5" :key="i" class="w-2 h-2 rounded-full"
                                        :class="i <= ligne.criticite ? (ligne.criticite >= 4 ? 'bg-rose-500' : (ligne.criticite >= 2 ? 'bg-amber-500' : 'bg-emerald-500')) : 'bg-surface-3'"></span>
                                </div>
                            </td>
                            <td v-show="colonnesVisibles.eligibilite" class="px-4 py-2.5">
                                <div class="flex gap-1 flex-wrap">
                                    <span v-if="ligne.zakat_el_fitr" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent/10 text-accent-dark">Zakat El Fitr</span>
                                    <span v-if="ligne.sadaqa" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">Sadaqa</span>
                                </div>
                            </td>
                            <td v-show="colonnesVisibles.est_hotel" class="px-4 py-2.5 text-ink-muted">{{ ligne.est_hotel ? 'Oui' : 'Non' }}</td>
                            <td v-show="colonnesVisibles.etudiant" class="px-4 py-2.5 text-ink-muted">{{ ligne.etudiant ? 'Oui' : 'Non' }}</td>
                            <td v-show="colonnesVisibles.langue" class="px-4 py-2.5 text-ink-muted">{{ LANGUES[ligne.langue] ?? ligne.langue }}</td>
                            <td v-show="colonnesVisibles.type_piece_identite" class="px-4 py-2.5 text-ink-muted">{{ (ligne.type_piece_identite && TYPE_PIECE_IDENTITE_LABELS[ligne.type_piece_identite]) ?? ligne.type_piece_identite ?? '—' }}</td>
                            <td v-show="colonnesVisibles.circonstances" class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" :title="ligne.circonstances ?? ''">{{ ligne.circonstances ?? '—' }}</td>
                            <td v-show="colonnesVisibles.ressentit" class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" :title="ligne.ressentit ?? ''">{{ ligne.ressentit ?? '—' }}</td>
                            <td v-show="colonnesVisibles.specificites" class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" :title="ligne.specificites ?? ''">{{ ligne.specificites ?? '—' }}</td>
                            <td v-show="colonnesVisibles.commentaire_dossier" class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" :title="ligne.commentaire_dossier ?? ''">{{ ligne.commentaire_dossier ?? '—' }}</td>
                            <td v-show="colonnesVisibles.created_at" class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ ligne.created_at ? new Date(ligne.created_at).toLocaleDateString('fr-FR') : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-surface-3">
                <div v-for="ligne in familles.data" :key="ligne.id" @click="ouvrir(ligne.id)"
                    class="px-4 py-3.5 border-l-4 active:bg-surface-2 transition-colors duration-150 cursor-pointer"
                    :class="[ETAT_COLORS_LISTERE[ligne.etat_dossier] ?? 'border-l-gray-300', ligne.probleme_traitement ? 'bg-rose-50/60' : '']">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-9 h-9 text-[12px] rounded-full flex items-center justify-center font-bold flex-shrink-0" :class="[avatarStyle(ligne.id).bg, avatarStyle(ligne.id).text]">
                                {{ initiales(ligne) }}
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-[13.5px] text-ink truncate">{{ ligne.prenom }} {{ ligne.nom }}</div>
                                <div class="text-[11.5px] text-ink-muted">#{{ ligne.id }} · {{ ligne.nombre_foyer }} pers.</div>
                                <span v-if="ligne.verrou" data-verrou class="inline-flex items-center gap-1 mt-0.5 px-1.5 py-0.5 rounded-full text-[10.5px] font-semibold border border-amber-300 bg-amber-50 text-amber-800" :title="titreVerrou(ligne.verrou)">🔒 {{ libelleVerrou(ligne.verrou) }}</span>
                            </div>
                        </div>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border" :class="ETAT_COLORS[ligne.etat_dossier] ?? ''">
                            {{ ligne.etat_dossier }}
                        </span>
                    </div>
                    <div v-if="ligne.probleme_traitement" class="text-[11px] text-rose-600 font-semibold mb-2">⚠️ {{ ligne.probleme_traitement }}</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 text-[12px] text-ink-muted mb-2.5">
                        <div class="truncate">📞 {{ ligne.telephone_formate }}</div>
                        <div class="truncate">📍 {{ ligne.adresse_complete }}</div>
                        <div v-if="ligne.quartier || ligne.ville" class="truncate">🏙️ {{ ligne.quartier?.nom ?? '—' }}<template v-if="ligne.ville">, {{ ligne.ville }}</template></div>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1" :title="`Criticité ${ligne.criticite}/5`">
                            <span v-for="i in 5" :key="i" class="w-2 h-2 rounded-full"
                                :class="i <= ligne.criticite ? (ligne.criticite >= 4 ? 'bg-rose-500' : (ligne.criticite >= 2 ? 'bg-amber-500' : 'bg-emerald-500')) : 'bg-surface-3'"></span>
                        </div>
                        <div class="flex gap-1 flex-wrap justify-end">
                            <span v-if="ligne.zakat_el_fitr" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent/10 text-accent-dark">Zakat El Fitr</span>
                            <span v-if="ligne.sadaqa" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">Sadaqa</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-4 py-3 border-t border-surface-3">
                <Paginator :meta="metaPagination" @change="(page) => visiter({ page })" />
            </div>
        </template>
    </div>
</template>
