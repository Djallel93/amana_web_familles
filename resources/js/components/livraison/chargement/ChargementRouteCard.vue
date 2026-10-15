<!-- resources/js/components/livraison/chargement/ChargementRouteCard.vue -->
<!--
    Carte d'UNE tournée sur l'écran Chargement (11/10/2026) — remplace partials/chargement-route.blade.php.
    Reprend ses indicateurs (urgence, statut dérivé du conditionnement, véhicule, contact chauffeur,
    colis à charger, pastille par famille) et ses actions (chargement confirmé, bénévole absent,
    problème de capacité, annuler le chargement), et porte désormais aussi :

      - « Préparation prioritaire » de toute la tournée (ChargementController::preparationPrioritaire) ;
      - la composition de la tournée (ChargementEditionController, ex-Suivi livraison) : ajouter une
        famille sans tournée, retirer une famille, changer de chauffeur (admin/gestionnaire),
        scinder, annuler la tournée. Verrouillée une fois chargée, sauf pour un admin — le serveur
        renvoie `verrouillee` et refuse de toute façon (RouteMutationService::refuserSiVerrouillee()).

    Émet « changed » après toute action réussie : la page recharge alors la liste complète plutôt
    que de fusionner la réponse (même raisonnement que RoutesPanel.vue : les mutations ne renvoient
    pas le modèle avec ses relations).
-->
<script setup lang="ts">
import { ref } from "vue";
import { useConfirm, usePrompt, useToast } from "@amana/shared-ui";
import { apiDelete, apiPost } from "../shared/api";
import PersonSelect from "../shared/PersonSelect.vue";
import type { PersonneResume } from "../shared/types";
import type { FamilleSansTournee, RouteChargement, UrlsChargement } from "./types";

const props = defineProps<{
    route: RouteChargement;
    urls: UrlsChargement;
    /** Familles sans tournée : réservoir de « Ajouter une famille ». */
    familles: FamilleSansTournee[];
    peutReassigner: boolean;
    /** Ligne apparue depuis le dernier rafraîchissement (surbrillance 5 s). */
    nouvelle?: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();
const confirmDialog = useConfirm();
const promptDialog = usePrompt();

const occupe = ref(false);
const idFamilleAAjouter = ref("");
const benevoleReassigne = ref<PersonneResume | null>(null);

function url(gabarit: string, etape?: number): string {
    const base = gabarit.replace("__ID__", String(props.route.id));
    return etape === undefined ? base : base.replace("__ETAPE__", String(etape));
}

/** Exécute une action serveur ; toast d'erreur sinon, `true` + rechargement de la liste en cas de succès. */
async function executer(action: () => Promise<{ ok: boolean; message?: string }>, succes?: string): Promise<boolean> {
    occupe.value = true;
    const resultat = await action();
    occupe.value = false;
    if (!resultat.ok) {
        toast.error(resultat.message ?? "L'action a échoué.");
        return false;
    }
    if (succes) toast.success(succes);
    emit("changed");
    return true;
}

async function confirmerChargement() {
    const ok = await confirmDialog.ask({
        title: "Confirmer le chargement ?",
        message: "Le chauffeur sera prévenu que son véhicule est chargé.",
        confirmLabel: "Chargement confirmé",
    });
    if (ok) await executer(() => apiPost(url(props.urls.confirmer)));
}

async function annulerChargement() {
    const ok = await confirmDialog.ask({
        title: "Annuler le chargement ?",
        message: "La tournée repasse « Prête » et un incident est ouvert pour les administrateurs.",
        confirmLabel: "Annuler le chargement",
        danger: true,
    });
    if (ok) await executer(() => apiPost(url(props.urls.annulerChargement)), "Chargement annulé — incident ouvert.");
}

async function signaler(titre: string, message: string, gabarit: string, succes: string) {
    const notes = await promptDialog.ask({
        title: titre,
        message,
        label: "Détails (optionnel)",
        confirmLabel: "Signaler",
    });
    if (notes === null) return;
    await executer(() => apiPost(url(gabarit), { notes }), succes);
}

const signalerAbsent = () =>
    signaler(
        "Bénévole absent",
        "Les livraisons non chargées de cette tournée repasseront en attente.",
        props.urls.benevoleAbsent,
        "Incident signalé — les livraisons non chargées repassent en attente.",
    );

const signalerCapacite = () =>
    signaler(
        "Problème de capacité",
        "Les administrateurs seront prévenus que le véhicule ne peut pas tout contenir.",
        props.urls.capacite,
        "Incident signalé à l'admin.",
    );

async function prioriser() {
    const ok = await confirmDialog.ask({
        title: "Préparation prioritaire",
        message:
            "Toutes les familles de cette tournée pas encore prêtes passeront en tête de la file Packaging. Cette demande ne peut pas être retirée. Continuer ?",
        confirmLabel: "Oui, prioriser",
    });
    if (ok) await executer(() => apiPost(url(props.urls.prioriser)), "Tournée passée en préparation prioritaire.");
}

async function ajouterFamille() {
    const id = parseInt(idFamilleAAjouter.value, 10);
    if (!id) return;
    const ok = await executer(
        () => apiPost(url(props.urls.ajouterFamille), { id_livraison: id }),
        "Famille ajoutée à la tournée.",
    );
    if (ok) idFamilleAAjouter.value = "";
}

async function retirerFamille(etapeId: number, familleId: number) {
    const ok = await confirmDialog.ask({
        message: `Retirer la famille #${familleId} de cette tournée ?`,
        danger: true,
    });
    if (ok) await executer(() => apiDelete(url(props.urls.retirerFamille, etapeId)), "Famille retirée de la tournée.");
}

async function reassigner() {
    // id_vehicule_type dérivé du profil du bénévole choisi (PersonSelect filtre avec-vehicule).
    if (!benevoleReassigne.value?.id_vehicule_type) {
        toast.error("Choisissez un bénévole ayant déclaré un véhicule.");
        return;
    }
    const ok = await executer(
        () =>
            apiPost(url(props.urls.reassigner), {
                id_benevole: benevoleReassigne.value?.id,
                id_vehicule_type: benevoleReassigne.value?.id_vehicule_type,
            }),
        "Tournée réassignée.",
    );
    if (ok) benevoleReassigne.value = null;
}

async function diviser() {
    const ok = await confirmDialog.ask({
        title: "Scinder la tournée",
        message: "La tournée sera scindée en deux tournées distinctes et le chauffeur en sera prévenu. Continuer ?",
        confirmLabel: "Scinder",
    });
    if (ok) await executer(() => apiPost(url(props.urls.diviser)), "Tournée scindée.");
}

async function supprimer() {
    const ok = await confirmDialog.ask({
        title: props.route.vide ? "Annuler cette tournée vide" : "Supprimer cette tournée",
        message: props.route.vide
            ? "Cette tournée n'a plus aucune famille : elle sera annulée. Continuer ?"
            : "La tournée sera annulée et ses familles repasseront non affectées. Continuer ?",
        confirmLabel: "Supprimer",
        danger: true,
    });
    if (ok) await executer(() => apiDelete(url(props.urls.supprimer)), "Tournée supprimée.");
}
</script>

<template>
    <div
        class="border rounded-xl p-4"
        :class="[
            route.urgence === 'famille'
                ? 'border-rose-400 border-2 bg-rose-50'
                : route.urgence === 'benevole'
                  ? 'border-amber-400 border-2 bg-amber-50'
                  : 'border-surface-border bg-surface',
            route.etat === 'chargee' ? 'opacity-60' : '',
            nouvelle ? 'ligne-nouvelle' : '',
        ]"
        :id="`route-${route.id}`"
    >
        <div class="flex items-center justify-between mb-2">
            <span class="text-[14px] font-medium text-ink">
                {{ route.benevole?.prenom ?? "" }} {{ route.benevole?.nom ?? "" }} — {{ route.etapes.length }} arrêt(s)
            </span>
            <div class="flex items-center gap-1.5">
                <span
                    v-if="route.urgence === 'famille'"
                    class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-rose-600 text-white"
                    >🔴 Urgent — famille</span
                >
                <span
                    v-else-if="route.urgence === 'benevole'"
                    class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-amber-600 text-white"
                    >🟠 Urgent — chauffeur</span
                >
                <span v-if="route.vide" class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-stone-200 text-ink"
                    >Vide</span
                >
                <span v-else class="text-[11px] font-medium px-2 py-0.5 rounded-full" :class="route.etat_style">{{
                    route.etat_libelle
                }}</span>
                <span class="text-[12px] text-ink-muted">{{ route.creneau_libelle }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-ink mb-3">
            <span>🚗 {{ route.vehicule ?? "Véhicule non renseigné" }}</span>
            <a v-if="route.benevole?.telephone" :href="`tel:${route.benevole.telephone}`" class="text-accent underline"
                >📞 {{ route.benevole.telephone }}</a
            >
            <span v-else class="text-ink-muted">📞 téléphone non renseigné</span>
            <span class="font-medium"
                >📦 {{ route.colis }} {{ route.etat === "chargee" ? "colis chargés" : "colis à charger" }}</span
            >
        </div>

        <p v-if="route.vide" class="text-[12.5px] text-ink-muted mb-3">
            Plus aucune famille dans cette tournée : ajoutez-en une ou annulez la tournée.
        </p>
        <ul v-else class="text-[12px] text-ink-muted space-y-1 mb-3">
            <li v-for="etape in route.etapes" :key="etape.id" class="flex items-center gap-1.5 flex-wrap">
                <span>Famille #{{ etape.famille_id }}</span>
                <span class="text-[10.5px] font-medium px-1.5 py-0.5 rounded-full" :class="etape.etat_style">{{
                    etape.etat_libelle
                }}</span>
                <span
                    v-if="etape.prioritaire"
                    class="text-[10.5px] font-semibold px-1.5 py-0.5 rounded-full bg-indigo-600 text-white"
                    >⭐ Prioritaire</span
                >
                <span v-if="etape.etudiant" class="text-sky-600">· étudiant</span>
                <span v-if="etape.est_hotel" class="text-amber-600">· hôtel</span>
                <span v-if="etape.nombre_enfant > 0" class="text-violet-600"
                    >· {{ etape.nombre_enfant }} enfant(s)</span
                >
                <button
                    v-if="!route.verrouillee"
                    type="button"
                    :disabled="occupe"
                    class="ml-auto text-[11px] text-rose-600 px-2 disabled:opacity-60"
                    @click="retirerFamille(etape.id, etape.famille_id)"
                >
                    retirer
                </button>
            </li>
        </ul>

        <div class="flex flex-wrap gap-2">
            <template v-if="route.peut_confirmer">
                <button
                    type="button"
                    :disabled="occupe"
                    class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60"
                    @click="confirmerChargement"
                >
                    Chargement confirmé
                </button>
                <button
                    type="button"
                    :disabled="occupe"
                    class="text-[12px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 disabled:opacity-60"
                    @click="signalerAbsent"
                >
                    Bénévole absent
                </button>
                <button
                    type="button"
                    :disabled="occupe"
                    class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60"
                    @click="signalerCapacite"
                >
                    Problème de capacité
                </button>
            </template>
            <button
                v-if="route.peut_prioriser"
                type="button"
                :disabled="occupe"
                class="text-[12px] px-3 py-1.5 rounded-lg border border-indigo-300 text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
                @click="prioriser"
            >
                ⭐ Préparation prioritaire
            </button>
            <button
                v-if="route.peut_annuler_chargement"
                type="button"
                :disabled="occupe"
                class="text-[12px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 disabled:opacity-60"
                @click="annulerChargement"
            >
                Annuler le chargement
            </button>
        </div>

        <details class="mt-3 border-t border-surface-border pt-3">
            <summary class="cursor-pointer text-[12.5px] text-ink-muted select-none">Modifier la composition</summary>

            <p v-if="route.verrouillee" class="text-[12px] text-ink-muted mt-2">
                Cette tournée est chargée ou en cours : seul un admin peut encore la modifier. Annulez d'abord le
                chargement si c'est une erreur.
            </p>
            <div v-else class="mt-3 space-y-3">
                <div class="flex flex-col sm:flex-row gap-2">
                    <select
                        v-model="idFamilleAAjouter"
                        class="flex-1 rounded-lg border border-surface-border px-2.5 py-1.5 text-[12.5px] min-h-[2.25rem]"
                    >
                        <option value="">Ajouter une famille sans tournée…</option>
                        <option v-for="f in familles" :key="f.id" :value="f.id">
                            Famille #{{ f.famille_id }} — {{ f.etat_libelle }}
                        </option>
                    </select>
                    <button
                        type="button"
                        :disabled="!idFamilleAAjouter || occupe"
                        class="min-h-[2.25rem] shrink-0 text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60"
                        @click="ajouterFamille"
                    >
                        Ajouter
                    </button>
                </div>

                <div v-if="peutReassigner" class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                    <div class="sm:col-span-2">
                        <PersonSelect
                            role="benevole"
                            avec-vehicule
                            placeholder="Nouveau chauffeur…"
                            v-model="benevoleReassigne"
                        />
                    </div>
                    <button
                        type="button"
                        :disabled="occupe || !benevoleReassigne"
                        class="min-h-[2.25rem] text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60"
                        @click="reassigner"
                    >
                        Réassigner
                    </button>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        v-if="route.etapes.length >= 2"
                        type="button"
                        :disabled="occupe"
                        class="min-h-[2rem] text-[11px] px-2.5 py-1 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60"
                        @click="diviser"
                    >
                        Scinder
                    </button>
                    <button
                        v-if="route.peut_supprimer"
                        type="button"
                        :disabled="occupe"
                        class="min-h-[2rem] text-[11px] px-2.5 py-1 rounded-lg border border-rose-200 text-rose-600 disabled:opacity-60"
                        @click="supprimer"
                    >
                        {{ route.vide ? "Annuler la tournée" : "Supprimer" }}
                    </button>
                </div>
            </div>
        </details>
    </div>
</template>

<style scoped>
@keyframes chargement-nouvelle {
    from {
        box-shadow: 0 0 0 4px rgb(16 185 129 / 0.75);
        background-color: rgb(209 250 229);
    }
    to {
        box-shadow: 0 0 0 0 rgb(16 185 129 / 0);
    }
}
.ligne-nouvelle {
    animation: chargement-nouvelle 5s ease-out 1;
}
@media (prefers-reduced-motion: reduce) {
    .ligne-nouvelle {
        animation: none;
        box-shadow: 0 0 0 3px rgb(16 185 129 / 0.75);
    }
}
</style>
