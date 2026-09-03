<!-- resources/js/components/livraison/shared/VehiculePicker.vue -->
<!--
    Picker de type de véhicule — remplace les inputs "ID véhicule" de la
    version placeholder du tableau de bord. Contrairement à PersonPicker,
    pas de recherche : ref_vehicules est un ensemble fixe de 8 lignes
    (voir VehiculeTypesController), une liste à choix radio suffit — même
    esprit visuel que l'étape "Véhicule" de BenevoleForm.vue (labels avec
    capacité/nombre de parts affichés), en plus compact pour tenir dans un
    formulaire de réassignation.

    Charge la liste une seule fois au montage (GET /vehicules, public —
    même endpoint que BenevoleForm.vue) : huit lignes, pas besoin de la
    recharger par instance si l'écran en affiche plusieurs (voir cache
    module-level ci-dessous).
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { apiGet } from './api';
import type { VehiculeType } from './types';

const props = defineProps<{
    modelValue: number | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [id: number | null];
}>();

// Cache module-level : partagé entre toutes les instances de ce composant
// dans la page (plusieurs tournées peuvent chacune afficher un picker
// véhicule) — évite un fetch par instance pour un référentiel fixe qui ne
// change jamais pendant une session.
let vehiculesCache: VehiculeType[] | null = null;
let chargementEnCours: Promise<VehiculeType[]> | null = null;

const vehicules = ref<VehiculeType[]>(vehiculesCache ?? []);
const chargement = ref(vehiculesCache === null);
const erreur = ref(false);

onMounted(async () => {
    if (vehiculesCache) {
        vehicules.value = vehiculesCache;
        chargement.value = false;
        return;
    }

    if (!chargementEnCours) {
        chargementEnCours = apiGet<VehiculeType[]>('/vehicules').then((resultat) => {
            if (!resultat.ok) throw new Error(resultat.message);
            vehiculesCache = resultat.data;
            return resultat.data;
        });
    }

    try {
        vehicules.value = await chargementEnCours;
    } catch {
        erreur.value = true;
        chargementEnCours = null;
    } finally {
        chargement.value = false;
    }
});
</script>

<template>
    <div>
        <p v-if="chargement" class="text-[12.5px] text-ink-muted px-1 py-1">Chargement des véhicules…</p>
        <p v-else-if="erreur" class="text-[12.5px] text-rose-600 px-1 py-1">Liste des véhicules indisponible, réessayez.</p>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <label v-for="vehicule in vehicules" :key="vehicule.id"
                class="flex flex-col gap-0.5 px-3 py-2.5 border rounded-md text-[13px] text-ink cursor-pointer select-none min-h-[2.5rem]"
                :class="modelValue === vehicule.id ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                <span class="flex items-center gap-2">
                    <input type="radio" :checked="modelValue === vehicule.id"
                        @change="emit('update:modelValue', vehicule.id)" class="w-4 h-4 accent-accent">
                    {{ vehicule.type }}
                </span>
                <span v-if="vehicule.capacite_kg > 0" class="text-[11px] text-ink-faint pl-6">
                    {{ vehicule.capacite_kg }}kg · {{ vehicule.nombre_part_max }} parts
                </span>
            </label>
        </div>
    </div>
</template>
