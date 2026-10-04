<!-- resources/js/components/livraison/campagnes/CampagneApercu.vue -->
<!--
    Grille de statistiques d'une campagne, affichée dans la ligne dépliée de
    la liste (CampagnesIndex.vue) — données de CampagnesController::apercu(),
    03/10/2026. Composant à part pour garder la liste lisible ; aucune
    logique, uniquement de la présentation.
-->
<script setup lang="ts">
import type { ApercuCampagne } from "../shared/types";

defineProps<{ apercu: ApercuCampagne }>();

const LABELS_CONTACT: Record<string, string> = {
    a_contacter: "À contacter",
    contacte: "Contactées",
    injoignable: "Injoignables",
    confirme: "Confirmées",
    rejetee: "Rejetées",
    archive: "Archivées",
};

const LABELS_TOURNEE: Record<string, string> = {
    planifiee: "Planifiées",
    chargement: "Chargement",
    charge: "Chargées",
    en_cours: "En cours",
    livraisons_terminees: "Livraisons terminées",
    terminee: "Terminées",
    packaging_annule: "Packaging annulé",
    annulee: "Annulées",
};

function nombre(n: number): string {
    return n.toLocaleString("fr-FR");
}
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[13px]">
        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Familles</h4>
            <p>
                <span class="font-semibold text-ink">{{ nombre(apercu.familles.total) }}</span> au total,
                <span class="font-semibold text-ink">{{ nombre(apercu.familles.confirmees) }}</span> confirmées
            </p>
            <p class="text-ink-muted">🏢 {{ nombre(apercu.familles.se_deplacent) }} se déplacent au QG</p>
        </div>

        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Contacts</h4>
            <p v-if="apercu.familles.total === 0" class="text-ink-muted">Aucune famille sélectionnée.</p>
            <div v-else class="flex flex-wrap gap-1.5">
                <template v-for="(label, code) in LABELS_CONTACT" :key="code">
                    <span
                        v-if="apercu.contacts[code] > 0"
                        class="px-2 py-0.5 rounded-full bg-surface border border-surface-border text-[12px]"
                    >
                        {{ label }} <strong>{{ nombre(apercu.contacts[code]) }}</strong>
                    </span>
                </template>
            </div>
        </div>

        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Bénévoles</h4>
            <p>
                <span class="font-semibold text-ink">{{ nombre(apercu.benevoles.disponibles) }}</span> disponible(s)
            </p>
            <p class="text-ink-muted">{{ nombre(apercu.benevoles.en_attente) }} en attente de confirmation</p>
        </div>

        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Poids</h4>
            <p>
                Estimé : <span class="font-semibold text-ink">{{ nombre(apercu.poids.estime_kg) }} kg</span>
            </p>
            <p>
                Collecté : <span class="font-semibold text-ink">{{ nombre(apercu.poids.collecte_kg) }} kg</span>
            </p>
        </div>

        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Tournées</h4>
            <p v-if="apercu.tournees.total === 0" class="text-ink-muted">Aucune tournée générée.</p>
            <template v-else>
                <p>
                    <span class="font-semibold text-ink">{{ nombre(apercu.tournees.total) }}</span> au total
                </p>
                <div class="flex flex-wrap gap-1.5 mt-1">
                    <span
                        v-for="(n, statut) in apercu.tournees.par_statut"
                        :key="statut"
                        class="px-2 py-0.5 rounded-full bg-surface border border-surface-border text-[12px]"
                    >
                        {{ LABELS_TOURNEE[statut] ?? statut }} <strong>{{ nombre(n) }}</strong>
                    </span>
                </div>
            </template>
        </div>

        <div class="rounded-lg bg-surface-2 p-3">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">Packaging</h4>
            <p v-if="apercu.packaging.taux === null" class="text-ink-muted">Aucune livraison confirmée.</p>
            <template v-else>
                <p>
                    <span class="font-semibold text-ink">{{ nombre(apercu.packaging.pretes) }}</span> /
                    {{ nombre(apercu.packaging.confirmees) }} prêtes ({{ apercu.packaging.taux }} %)
                </p>
                <div class="h-1.5 rounded-full bg-surface-border mt-1.5 overflow-hidden">
                    <div class="h-full bg-accent" :style="{ width: `${apercu.packaging.taux}%` }"></div>
                </div>
            </template>
        </div>

        <div class="rounded-lg bg-surface-2 p-3 sm:col-span-2">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted mb-1.5">
                Livraisons (familles confirmées)
            </h4>
            <p>
                ✅ <span class="font-semibold text-ink">{{ nombre(apercu.livraisons.livrees) }}</span> livrées · ⏭️
                <span class="font-semibold text-ink">{{ nombre(apercu.livraisons.ignorees) }}</span> ignorées · ⏳
                <span class="font-semibold text-ink">{{ nombre(apercu.livraisons.en_attente) }}</span> en attente
            </p>
        </div>
    </div>
</template>
