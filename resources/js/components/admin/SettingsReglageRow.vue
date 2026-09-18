<!-- resources/js/components/admin/SettingsReglageRow.vue -->
<!--
    Port fidèle de resources/views/settings/_reglage_row.blade.php,
    extrait le 05/09/2026 côté Blade pour être partagé entre les onglets
    "Général" et "Itinéraires" de l'écran Paramètres — même raison
    d'extraction reconduite ici (Section E4 du refactor, 16/09/2026,
    chunk settings) plutôt que dupliquer ce gabarit deux fois dans
    Settings/Index.vue.

    Contrairement aux autres composants convertis dans ce refactor, ceci
    n'a jamais été un îlot séparé — il vit désormais comme un simple
    enfant Vue dans le <form> de la page Paramètres, exactement comme la
    Blade l'@include-ait dans son propre <form> POST classique. Les name=
    des champs sont inchangés (settings[{cle}]) : c'est bien ce <form>
    natif (pas un <template> Vue autour d'un state réactif) qui est
    soumis en POST classique vers SettingsController::update() (vendor,
    inchangé par ce chunk).

    old()/@error() Blade devenus des props (oldValeur/erreur) — calculés
    par le parent depuis usePage().props.old/errors (voir
    Settings/Index.vue), une seule fois par ligne plutôt que reproduire
    ici la même lecture de props pour chaque instance.
-->
<script setup lang="ts">
export interface ReglageData {
    type: 'boolean' | 'encrypted' | 'float' | 'integer' | 'string';
    libelle: string;
    description: string | null;
    valeur: unknown;
}

const props = defineProps<{
    cle: string;
    data: ReglageData;
    oldValeur: unknown;
    erreur?: string;
}>();

// Reprend exactement old("settings.{cle}", $data['valeur']) : la valeur
// précédemment soumise (si soumission invalide) prime sur la valeur
// actuelle en base.
const valeurAffichee = props.oldValeur !== undefined ? props.oldValeur : props.data.valeur;
</script>

<template>
    <div class="p-4 flex gap-4" :class="data.type === 'encrypted' ? 'flex-col' : 'items-center justify-between'">
        <div class="min-w-0">
            <label :for="`setting-${cle}`" class="block text-sm font-semibold text-ink">{{ data.libelle }}</label>
            <p v-if="data.description" class="text-xs text-ink-muted mt-0.5">{{ data.description }}</p>
        </div>
        <div class="flex-shrink-0" :class="data.type === 'encrypted' ? 'w-full' : 'w-56'">
            <!-- Interrupteur (remplace le <select> Activé/Désactivé le 29/08/2026) —
                 le hidden à '0' avant la checkbox garantit qu'une valeur est
                 toujours soumise même décochée (la checkbox l'écrase à '1' si
                 cochée, même name donc même clé dans settings[], le dernier gagne). -->
            <label v-if="data.type === 'boolean'" class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" :name="`settings[${cle}]`" value="0">
                <input type="checkbox" :id="`setting-${cle}`" :name="`settings[${cle}]`" value="1"
                    :checked="Boolean(valeurAffichee)" class="sr-only peer">
                <div
                    class="w-11 h-6 bg-ink-faint/40 rounded-full peer peer-checked:bg-accent transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5">
                </div>
            </label>
            <textarea v-else-if="data.type === 'encrypted'" :id="`setting-${cle}`" :name="`settings[${cle}]`" rows="3"
                v-text="valeurAffichee"
                class="w-full max-w-md px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-xs font-mono bg-surface-2 text-ink resize-y"></textarea>
            <input v-else-if="data.type === 'float'" type="number" step="any" :id="`setting-${cle}`"
                :name="`settings[${cle}]`" :value="valeurAffichee"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
            <input v-else-if="data.type === 'integer'" type="number" step="1" :id="`setting-${cle}`"
                :name="`settings[${cle}]`" :value="valeurAffichee"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
            <input v-else type="text" :id="`setting-${cle}`" :name="`settings[${cle}]`" :value="valeurAffichee"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
        </div>
        <span v-if="erreur" class="block w-full text-xs text-rose-600 mt-1">{{ erreur }}</span>
    </div>
</template>
