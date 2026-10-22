<!-- resources/js/components/livraison/chargement/FamilleResume.vue -->
<!--
    Bloc d'identité d'une famille sur l'écran Chargement (octobre 2026) : numéro, pastilles
    « émoji + nombre + libellé », adresse complète et téléphones. Jamais le nom de la famille :
    l'équipe chargement identifie les familles par leur numéro. Partagé entre les arrêts d'une
    tournée et la liste « familles sans tournée ».
-->
<script setup lang="ts">
import { computed } from "vue";
import { formaterTelephoneFr, lienTelephone } from "@amana/shared-ui";
import { pastillesFamille } from "./pastilles";

const props = defineProps<{
    familleId: number;
    etudiant: boolean;
    estHotel: boolean;
    nombreEnfant: number;
    colis: number;
    adresse: string;
    telephone: string | null;
    telephoneBis: string | null;
    prioritaire?: boolean;
}>();

const pastilles = computed(() =>
    pastillesFamille({
        etudiant: props.etudiant,
        estHotel: props.estHotel,
        nombreEnfant: props.nombreEnfant,
        colis: props.colis,
    }),
);

const telephones = computed(() =>
    [props.telephone, props.telephoneBis].filter((t): t is string => !!t && t.trim() !== ""),
);
</script>

<template>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-[14px] font-semibold text-ink">#{{ familleId }}</span>
            <span
                v-if="prioritaire"
                class="text-[10.5px] font-semibold px-1.5 py-0.5 rounded-full bg-indigo-600 text-white"
                >⭐ Prioritaire</span
            >
            <span
                v-for="pastille in pastilles"
                :key="pastille.cle"
                class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full"
                :class="pastille.style"
                >{{ pastille.texte }}</span
            >
        </div>
        <p class="mt-1 text-[12.5px] text-ink-muted break-words">📍 {{ adresse || "Adresse non renseignée" }}</p>
        <p v-if="telephones.length > 0" class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px]">
            <a
                v-for="numero in telephones"
                :key="numero"
                :href="`tel:${lienTelephone(numero)}`"
                class="text-accent underline whitespace-nowrap"
                >📞 {{ formaterTelephoneFr(numero) }}</a
            >
        </p>
        <p v-else class="mt-0.5 text-[12.5px] text-ink-muted">📞 téléphone non renseigné</p>
    </div>
</template>
