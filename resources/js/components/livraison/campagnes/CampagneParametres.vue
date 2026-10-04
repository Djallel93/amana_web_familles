<!-- resources/js/components/livraison/campagnes/CampagneParametres.vue -->
<!--
    Paramètres d'une campagne (03/10/2026) — page vers laquelle le hub
    renvoie via le bouton « Paramètres » en haut à droite. Trois onglets :

      - HQ & commentaire : édition inline du point de départ, du max de
        livraisons par tournée, de la fenêtre d'accueil QG et du commentaire
        (code de l'ancienne page détail, 05/09/2026 prompt §1.2/§1.3 +
        24/09/2026 + 08/09/2026 §2.2.3), historique des poids moyens ;
      - Journées : liste + « + Ajouter une journée » ;
      - Équipes : EquipeMembresQueue.vue, qui vivait sur sa propre page
        (/campagnes/{id}/equipes, désormais redirigée ici).
-->
<script setup lang="ts">
import { ref, reactive, computed } from "vue";
import { useToast } from "@amana/shared-ui";
import { apiPatch, apiPost } from "../shared/api";
import HqCoordinatesAutocomplete from "../../admin/HqCoordinatesAutocomplete.vue";
import EquipeMembresQueue, { type LigneEquipe } from "./EquipeMembresQueue.vue";
import type {
    Campagne,
    CampagneJournee,
    CampagnePoidsMoyenHistorique,
} from "../shared/types";

const toast = useToast();

type Onglet = "hq" | "journees" | "equipes";

const props = defineProps<{
    campagne: Campagne;
    onglet: Onglet;
    googlePlacesKey: string;
    updateUrl: string;
    ajouterJourneeUrl: string;
    lignesEquipe: LigneEquipe[];
    ajouterEquipeUrl: string;
    retirerEquipeUrlTemplate: string;
}>();

// Copie locale mutable : enregistrerEdition() fusionne la réponse par spread.
const campagne = ref<Campagne>({ ...props.campagne });
const googlePlacesKey = props.googlePlacesKey;
const journees = ref<CampagneJournee[]>(campagne.value.journees ?? []);

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}

// ── Onglets ──────────────────────────────────────────────────────────────
const onglet = ref<Onglet>(props.onglet);
const ONGLETS: { id: Onglet; label: string }[] = [
    { id: "hq", label: "HQ & commentaire" },
    { id: "journees", label: "Journées" },
    { id: "equipes", label: "Équipes" },
];

function choisirOnglet(id: Onglet) {
    onglet.value = id;
    // Garde l'onglet dans l'URL (rechargement / lien partagé) sans navigation.
    const url = new URL(window.location.href);
    url.searchParams.set("onglet", id);
    window.history.replaceState(window.history.state, "", url);
}

// ── Ajout d'une journée ──────────────────────────────────────────────────
const afficherFormAjoutJournee = ref(false);
const nouvelleJourneeDate = ref("");
const nouvelleJourneeLabel = ref("");
const chargementAjoutJournee = ref(false);
const erreurAjoutJournee = ref("");

async function ajouterJournee() {
    if (!nouvelleJourneeDate.value) {
        erreurAjoutJournee.value = "Choisissez une date.";
        return;
    }
    chargementAjoutJournee.value = true;
    erreurAjoutJournee.value = "";

    const resultat = await apiPost<{
        success: boolean;
        journee: CampagneJournee;
    }>(props.ajouterJourneeUrl, {
        date: nouvelleJourneeDate.value,
        label: nouvelleJourneeLabel.value || null,
    });
    chargementAjoutJournee.value = false;

    if (!resultat.ok) {
        erreurAjoutJournee.value = resultat.message;
        return;
    }

    journees.value = [...journees.value, resultat.data.journee];
    nouvelleJourneeDate.value = "";
    nouvelleJourneeLabel.value = "";
    afficherFormAjoutJournee.value = false;
    toast.success("Journée ajoutée.");
}


// ── HQ propre à la campagne + commentaire (05/09/2026, prompt §1.2/§1.3) ──
// Édition inline sur cette page (pas de page d'édition séparée dans cette
// app) — hq_latitude/hq_longitude préremplies au réglage global à la
// création (voir CampagnesController::store()), simples champs numériques
// éditables ici plutôt que de réintégrer le widget Google Places de
// Paramètres (HqCoordinatesAutocomplete.vue, conçu pour cibler des inputs
// DOM par id sur cette page-là spécifiquement) : l'adresse saisie ici sert
// avant tout de LIBELLÉ de log, pas de source d'autorité pour le calcul de
// tournée (voir docblock de la migration campagnes).
const afficherFormEdition = ref(false);
const formEdition = reactive({
    commentaire: campagne.value.commentaire ?? "",
    hq_adresse: campagne.value.hq_adresse ?? "",
    hq_latitude: campagne.value.hq_latitude ?? "",
    hq_longitude: campagne.value.hq_longitude ?? "",
    // Ajouté le 08/09/2026 (prompt §2.2.3) — même statut que hq_* ci-dessus :
    // préremplie au réglage global à la création, éditable ici au cas par cas.
    livraisons_max_par_tournee: campagne.value.livraisons_max_par_tournee ?? "",
    // Ajoutés le 24/09/2026 (prompt de cette date §2/§4) — fenêtre
    // d'accueil QG des familles se_deplace, voir RetraitHqSchedulingService
    // côté back. <input type="time"> natif → déjà au format "HH:MM"
    // attendu par CampagnesController::update() (date_format:H:i).
    heure_debut_arrivee_hq: (campagne.value.heure_debut_arrivee_hq ?? "").slice(0, 5),
    heure_fin_arrivee_hq: (campagne.value.heure_fin_arrivee_hq ?? "").slice(0, 5),
});
const chargementEdition = ref(false);
const erreurEdition = ref("");

async function enregistrerEdition() {
    chargementEdition.value = true;
    erreurEdition.value = "";

    const resultat = await apiPatch<{ success: boolean; campagne: Campagne }>(
        props.updateUrl,
        {
            commentaire: formEdition.commentaire || null,
            hq_adresse: formEdition.hq_adresse || null,
            hq_latitude:
                formEdition.hq_latitude === ""
                    ? null
                    : Number(formEdition.hq_latitude),
            hq_longitude:
                formEdition.hq_longitude === ""
                    ? null
                    : Number(formEdition.hq_longitude),
            livraisons_max_par_tournee:
                formEdition.livraisons_max_par_tournee === ""
                    ? null
                    : Number(formEdition.livraisons_max_par_tournee),
            heure_debut_arrivee_hq: formEdition.heure_debut_arrivee_hq || null,
            heure_fin_arrivee_hq: formEdition.heure_fin_arrivee_hq || null,
        },
    );
    chargementEdition.value = false;

    if (!resultat.ok) {
        erreurEdition.value = resultat.message;
        return;
    }

    campagne.value = { ...campagne.value, ...resultat.data.campagne };
    afficherFormEdition.value = false;
    toast.success("HQ & commentaire confirmés.");
}

// Couleur de la section HQ & commentaire (09/09/2026, prompt de cette date
// §3.2 ; orange RETIRÉ le 09/09/2026, prompt de cette date §1.4 : "If HQ
// was define at campagne creation do not color HQ & commentaire section
// in orange" — un HQ est TOUJOURS défini à la création dès que le réglage
// global l'est (CampagnesController::store() le recopie automatiquement),
// donc l'ancien état orange "hérité mais jamais confirmé" ne signalait pas
// une vraie donnée manquante. hq_confirmee_le reste posé par le bouton
// "Confirmer" (traçabilité côté serveur) mais n'a plus d'effet visuel ici.
// Seul cas restant : rouge si cette campagne n'a AUCUN HQ du tout (ni
// saisi, ni hérité — ne peut arriver que si le réglage global lui-même
// n'était pas configuré au moment de la création).
const hqCouleur = computed<"rouge" | null>(() => {
    if (!campagne.value.hq_latitude && !campagne.value.hq_longitude)
        return "rouge";
    return null;
});


// ── Historique des poids moyens (lecture seule ici — édition sur l'écran
//    Packaging, voir le prompt §5.2 : "Add a section... under Packaging") ─
const historiquePoids = ref<CampagnePoidsMoyenHistorique[]>(
    campagne.value.poids_moyen_historique ?? [],
);

const nbMembres = computed(() => props.lignesEquipe.length);
</script>

<template>
    <div>
        <div role="tablist" class="flex gap-1 border-b border-surface-border mb-5 overflow-x-auto">
            <button v-for="o in ONGLETS" :key="o.id" type="button" role="tab" :aria-selected="onglet === o.id"
                @click="choisirOnglet(o.id)"
                class="px-4 py-2 text-[13px] font-medium whitespace-nowrap border-b-2 -mb-px transition-colors"
                :class="onglet === o.id ? 'border-accent text-accent' : 'border-transparent text-ink-muted hover:text-ink'">
                {{ o.label }}
                <span v-if="o.id === 'equipes' && nbMembres > 0"
                    class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark">{{ nbMembres }}</span>
            </button>
        </div>

        <div v-if="onglet === 'hq'">
        <!--
            HQ + commentaire (05/09/2026, prompt §1.2/§1.3).
            Couleur (09/09/2026, prompt de cette date §3.2 ; orange retiré
            le 09/09/2026, prompt de cette date §1.4) — voir hqCouleur
            ci-dessus : rouge = aucun HQ du tout (ni saisi, ni réglage
            global configuré).
        -->
        <div
            class="bg-surface border rounded-xl p-5 mb-6"
            :class="{
                'border-rose-300 bg-rose-50/60': hqCouleur === 'rouge',
                'border-surface-border': hqCouleur === null,
            }"
        >
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-[14px] font-medium text-ink">
                    HQ &amp; commentaire
                </h2>
                <button
                    type="button"
                    @click="afficherFormEdition = !afficherFormEdition"
                    class="text-[12.5px] text-accent"
                >
                    {{ afficherFormEdition ? "Fermer" : "Modifier" }}
                </button>
            </div>

            <template v-if="!afficherFormEdition">
                <p class="text-[13px] text-ink-muted">
                    HQ : {{ campagne.hq_adresse || "non renseigné" }}
                    <span v-if="campagne.hq_latitude && campagne.hq_longitude">
                        ({{ campagne.hq_latitude }},
                        {{ campagne.hq_longitude }})
                    </span>
                </p>
                <p class="text-[13px] text-ink-muted mt-1">
                    Max livraisons/tournée :
                    {{
                        campagne.livraisons_max_par_tournee ?? "réglage global"
                    }}
                </p>
                <p class="text-[13px] text-ink-muted mt-1">
                    Fenêtre d'accueil QG (familles se déplaçant) :
                    {{ campagne.heure_debut_arrivee_hq?.slice(0, 5) ?? "08:00" }}
                    –
                    {{ campagne.heure_fin_arrivee_hq?.slice(0, 5) ?? "19:00" }}
                </p>
                <p class="text-[13px] text-ink mt-2 whitespace-pre-wrap">
                    {{ campagne.commentaire || "Aucun commentaire." }}
                </p>
            </template>

            <form v-else @submit.prevent="enregistrerEdition" class="space-y-3">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Adresse HQ (libellé, pour le log)</label
                    >
                    <input
                        v-model="formEdition.hq_adresse"
                        type="text"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <!--
                    Autocomplétion Google Maps + bascule saisie manuelle
                    (05/09/2026, prompt §1.1) — même composant que l'écran
                    Paramètres (HqCoordinatesAutocomplete.vue, généralisé
                    ce même jour pour être réutilisable ainsi). Écrit
                    directement dans les 2 inputs ci-dessous via leur id
                    (campagne-hq-lat/campagne-hq-lng) + un évènement
                    'input', que v-model capte normalement.
                -->
                <HqCoordinatesAutocomplete
                    :google-places-key="googlePlacesKey"
                    target-lat-id="campagne-hq-lat"
                    target-lng-id="campagne-hq-lng"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Latitude</label
                        >
                        <input
                            id="campagne-hq-lat"
                            v-model="formEdition.hq_latitude"
                            type="number"
                            step="any"
                            readonly
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                        />
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Longitude</label
                        >
                        <input
                            id="campagne-hq-lng"
                            v-model="formEdition.hq_longitude"
                            type="number"
                            step="any"
                            readonly
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Max livraisons/tournée</label
                    >
                    <input
                        v-model="formEdition.livraisons_max_par_tournee"
                        type="number"
                        min="1"
                        step="1"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <!--
                    Fenêtre d'accueil QG des familles se_deplace — ajouté
                    le 24/09/2026 (prompt de cette date §2/§4). Bornées à
                    08h-19h côté serveur (CampagnesController::update()),
                    pas de min/max ici : un dépassement se traduit par un
                    message d'erreur clair plutôt qu'un input qui refuse
                    silencieusement la saisie.
                -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Retrait QG — début</label
                        >
                        <input
                            v-model="formEdition.heure_debut_arrivee_hq"
                            type="time"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                        />
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Retrait QG — fin</label
                        >
                        <input
                            v-model="formEdition.heure_fin_arrivee_hq"
                            type="time"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Commentaire</label
                    >
                    <textarea
                        v-model="formEdition.commentaire"
                        rows="3"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px]"
                    ></textarea>
                </div>
                <!-- Renommé "Enregistrer" → "Confirmer" (09/09/2026, prompt
                     §3.2) : pressé = HQ confirmé pour cette campagne, voir
                     hq_confirmee_le/hqCouleur ci-dessus. -->
                <button
                    type="submit"
                    :disabled="chargementEdition"
                    class="min-h-[2.25rem] text-[13px] px-4 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60"
                >
                    {{ chargementEdition ? "Confirmation…" : "Confirmer" }}
                </button>
                <p v-if="erreurEdition" class="text-[13px] text-rose-600">
                    {{ erreurEdition }}
                </p>
            </form>

            <div
                v-if="historiquePoids.length > 0"
                class="mt-4 pt-4 border-t border-surface-border"
            >
                <p class="text-[12px] font-medium text-ink-muted mb-1">
                    Historique des poids moyens
                </p>
                <p class="text-[12px] text-ink-muted">
                    Voir l'écran Packaging pour modifier les poids moyens et
                    consulter l'historique complet.
                </p>
            </div>
        </div>

        </div>

        <div v-else-if="onglet === 'journees'">
            <ul v-if="journees.length > 0" class="mb-4 space-y-1.5">
                <li v-for="journee in journees" :key="journee.id"
                    class="bg-surface border border-surface-border rounded-lg px-4 py-2.5 text-[13px] text-ink">
                    <span class="font-medium">{{ formatDateFr(journee.date) }}</span>
                    <span v-if="journee.label" class="text-ink-muted"> — {{ journee.label }}</span>
                </li>
            </ul>
            <p v-else class="text-[13px] text-ink-muted mb-4">Aucune journée.</p>
        <div class="mb-6">
            <button
                v-if="!afficherFormAjoutJournee"
                type="button"
                @click="afficherFormAjoutJournee = true"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50"
            >
                + Ajouter une journée
            </button>
            <form
                v-else
                @submit.prevent="ajouterJournee"
                class="flex flex-col sm:flex-row gap-2 sm:items-end bg-surface border border-surface-border rounded-xl p-4"
            >
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Date</label
                    >
                    <input
                        v-model="nouvelleJourneeDate"
                        type="date"
                        required
                        class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <div class="flex-1">
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Label (optionnel)</label
                    >
                    <input
                        v-model="nouvelleJourneeLabel"
                        type="text"
                        placeholder="ex: Livraison (jour 2)"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="chargementAjoutJournee"
                        class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60"
                    >
                        {{ chargementAjoutJournee ? "Ajout…" : "Ajouter" }}
                    </button>
                    <button
                        type="button"
                        @click="afficherFormAjoutJournee = false"
                        class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted"
                    >
                        Annuler
                    </button>
                </div>
            </form>
            <p v-if="erreurAjoutJournee" class="text-[13px] text-rose-600 mt-2">
                {{ erreurAjoutJournee }}
            </p>
        </div>

        </div>

        <div v-else>
            <EquipeMembresQueue :campagne="campagne" :lignes="lignesEquipe" :ajouter-url="ajouterEquipeUrl"
                :retirer-url-template="retirerEquipeUrlTemplate" />
        </div>
    </div>
</template>
