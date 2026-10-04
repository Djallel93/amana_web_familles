<!-- resources/js/components/familles/DocumentRows.vue -->
<!--
    Lignes de justificatifs avec bouton « + » (01/10/2026, prompt de cette
    date §4.2) — UNE seule implémentation de l'interface pour les deux
    écrans qui déposent des documents :
      - IntakeForm.vue (formulaire public FR/EN/AR) : lignes LOCALES (File +
        libellé), envoyées au submit ;
      - DetailPanel.vue (onglet Documents de la fiche) : lignes PERSISTÉES,
        chaque geste appelle l'API (ajout / renommage-remplacement / suppression).

    Composant purement présentationnel : il ne sait rien du stockage. Le
    parent lui passe `rows` et réagit à `add` / `update` / `remove` — c'est ce
    qui permet de partager le même rendu entre un état local et un état
    serveur. Chaque ligne a un libellé OPTIONNEL : s'il est saisi, le fichier
    prend ce nom (extension d'origine conservée, voir
    App\Models\FamilleDocument::nomAvecLabel()).

    Ajout en deux temps : « + » ouvre le sélecteur de fichier, puis une ligne
    brouillon propose le libellé et « Ajouter » — le libellé est ainsi connu
    AVANT l'envoi (le fichier est nommé dès l'upload côté fiche, pas
    renommé après coup). Plafond `max` (5 par section) : « + » désactivé une
    fois atteint.

    Classes logiques (ms-/me-/text-start) plutôt que gauche/droite : le
    formulaire public s'affiche aussi en arabe (dir="rtl").
-->
<script setup lang="ts">
import { computed, ref } from "vue";

export interface DocumentRow {
    /** Identifiant stable de la ligne (id base de données, ou clé locale). */
    key: string | number;
    /** Nom de fichier affiché (déjà libellé le cas échéant). */
    name: string;
    /** Valeur pré-remplie dans le champ libellé à l'édition (peut être vide). */
    label: string;
    /** Lien de consultation, si le document est déjà stocké. */
    href?: string;
}

export interface DocumentRowsStrings {
    add: string;
    label: string;
    labelPlaceholder: string;
    confirmAdd: string;
    cancel: string;
    edit: string;
    remove: string;
    save: string;
    replaceFile: string;
    maxReached: string;
}

export interface DocumentRowsTone {
    border: string;
    bg: string;
    text: string;
    badge: string;
    button: string;
}

const props = withDefaults(
    defineProps<{
        rows: DocumentRow[];
        max?: number;
        accept?: string;
        strings?: Partial<DocumentRowsStrings>;
        tone?: Partial<DocumentRowsTone>;
        busy?: boolean;
        error?: string;
    }>(),
    {
        max: 5,
        accept: ".pdf,.jpg,.jpeg,.png,.doc,.docx",
        busy: false,
        error: "",
    },
);

const emit = defineEmits<{
    add: [file: File, label: string];
    update: [key: string | number, label: string, file: File | null];
    remove: [key: string | number];
}>();

const DEFAUTS: DocumentRowsStrings = {
    add: "Ajouter un fichier",
    label: "Libellé (optionnel)",
    labelPlaceholder: "Ex. Passeport Karim",
    confirmAdd: "Ajouter",
    cancel: "Annuler",
    edit: "Modifier",
    remove: "Supprimer",
    save: "Enregistrer",
    replaceFile: "Remplacer le fichier",
    maxReached: "Maximum atteint",
};

const t = computed<DocumentRowsStrings>(() => ({ ...DEFAUTS, ...props.strings }));

const couleurs = computed<DocumentRowsTone>(() => ({
    border: "border-ink-faint",
    bg: "bg-surface",
    text: "text-ink",
    badge: "bg-ink-faint",
    button: "bg-accent text-white",
    ...props.tone,
}));

const complet = computed(() => props.rows.length >= props.max);

// ── Ajout (ligne brouillon) ─────────────────────────────────────────────
const entreeAjout = ref<HTMLInputElement | null>(null);
const brouillonFichier = ref<File | null>(null);
const brouillonLabel = ref("");

function ouvrirSelecteur() {
    if (complet.value || props.busy) return;
    entreeAjout.value?.click();
}

function onFichierAjoute(e: Event) {
    const entree = e.target as HTMLInputElement;
    brouillonFichier.value = entree.files?.[0] ?? null;
    brouillonLabel.value = "";
    // Permet de re-sélectionner le même fichier après un « Annuler ».
    entree.value = "";
}

function annulerBrouillon() {
    brouillonFichier.value = null;
    brouillonLabel.value = "";
}

function confirmerBrouillon() {
    if (!brouillonFichier.value) return;
    emit("add", brouillonFichier.value, brouillonLabel.value.trim());
    annulerBrouillon();
}

// ── Édition d'une ligne existante ───────────────────────────────────────
const cleEnEdition = ref<string | number | null>(null);
const editionLabel = ref("");
const editionFichier = ref<File | null>(null);
const entreeRemplacement = ref<HTMLInputElement | null>(null);

function commencerEdition(ligne: DocumentRow) {
    cleEnEdition.value = ligne.key;
    editionLabel.value = ligne.label;
    editionFichier.value = null;
}

function annulerEdition() {
    cleEnEdition.value = null;
    editionFichier.value = null;
}

function onFichierRemplace(e: Event) {
    const entree = e.target as HTMLInputElement;
    editionFichier.value = entree.files?.[0] ?? null;
    entree.value = "";
}

function enregistrerEdition(ligne: DocumentRow) {
    emit("update", ligne.key, editionLabel.value.trim(), editionFichier.value);
    annulerEdition();
}
</script>

<template>
    <div class="space-y-2">
        <ul v-if="rows.length" class="space-y-2">
            <li
                v-for="ligne in rows"
                :key="ligne.key"
                class="rounded-md border px-3 py-2"
                :class="[couleurs.border, couleurs.bg]"
            >
                <!-- Affichage -->
                <div v-if="cleEnEdition !== ligne.key" class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full shrink-0" :class="couleurs.badge" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <a
                            v-if="ligne.href"
                            :href="ligne.href"
                            target="_blank"
                            rel="noopener"
                            class="block truncate text-[13px] font-semibold text-start hover:underline"
                            :class="couleurs.text"
                        >
                            {{ ligne.name }}
                        </a>
                        <span v-else class="block truncate text-[13px] font-semibold text-start" :class="couleurs.text">
                            {{ ligne.name }}
                        </span>
                    </div>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="commencerEdition(ligne)"
                        class="shrink-0 min-h-[2rem] min-w-[2rem] rounded-md text-[13px] text-ink-muted hover:bg-black/5 disabled:opacity-50"
                        :title="t.edit"
                        :aria-label="t.edit"
                    >
                        ✏️
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="emit('remove', ligne.key)"
                        class="shrink-0 min-h-[2rem] min-w-[2rem] rounded-md text-[13px] text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                        :title="t.remove"
                        :aria-label="t.remove"
                    >
                        🗑️
                    </button>
                </div>

                <!-- Édition : libellé + remplacement du fichier -->
                <div v-else class="space-y-2">
                    <label class="block text-[11px] font-semibold text-ink-muted text-start">{{ t.label }}</label>
                    <input
                        v-model="editionLabel"
                        type="text"
                        maxlength="100"
                        :placeholder="t.labelPlaceholder"
                        class="w-full px-2.5 py-1.5 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent"
                        @keydown.enter.prevent="enregistrerEdition(ligne)"
                        @keydown.esc="annulerEdition"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="entreeRemplacement?.click()"
                            class="min-h-[2rem] px-2.5 py-1 rounded-md border border-ink-faint text-[12px] text-ink-muted hover:bg-black/5"
                        >
                            📎 {{ t.replaceFile }}
                        </button>
                        <span v-if="editionFichier" class="text-[11.5px] text-ink truncate max-w-[14rem]">{{
                            editionFichier.name
                        }}</span>
                        <input
                            ref="entreeRemplacement"
                            type="file"
                            :accept="accept"
                            class="hidden"
                            @change="onFichierRemplace"
                        />
                    </div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="busy"
                            @click="enregistrerEdition(ligne)"
                            class="min-h-[2rem] px-3 py-1 rounded-md text-[12.5px] font-semibold disabled:opacity-50"
                            :class="couleurs.button"
                        >
                            {{ t.save }}
                        </button>
                        <button
                            type="button"
                            @click="annulerEdition"
                            class="min-h-[2rem] px-3 py-1 rounded-md border border-ink-faint text-[12.5px] text-ink-muted"
                        >
                            {{ t.cancel }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <!-- Ligne brouillon : fichier choisi, libellé optionnel, confirmation -->
        <div
            v-if="brouillonFichier"
            class="rounded-md border border-dashed px-3 py-2 space-y-2"
            :class="[couleurs.border, couleurs.bg]"
        >
            <p class="text-[12.5px] font-semibold truncate text-start" :class="couleurs.text">
                📎 {{ brouillonFichier.name }}
            </p>
            <label class="block text-[11px] font-semibold text-ink-muted text-start">{{ t.label }}</label>
            <input
                v-model="brouillonLabel"
                type="text"
                maxlength="100"
                :placeholder="t.labelPlaceholder"
                class="w-full px-2.5 py-1.5 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent"
                @keydown.enter.prevent="confirmerBrouillon"
                @keydown.esc="annulerBrouillon"
            />
            <div class="flex gap-2">
                <button
                    type="button"
                    :disabled="busy"
                    @click="confirmerBrouillon"
                    class="min-h-[2rem] px-3 py-1 rounded-md text-[12.5px] font-semibold disabled:opacity-50"
                    :class="couleurs.button"
                >
                    {{ t.confirmAdd }}
                </button>
                <button
                    type="button"
                    @click="annulerBrouillon"
                    class="min-h-[2rem] px-3 py-1 rounded-md border border-ink-faint text-[12.5px] text-ink-muted"
                >
                    {{ t.cancel }}
                </button>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                :disabled="complet || busy || !!brouillonFichier"
                @click="ouvrirSelecteur"
                class="inline-flex items-center gap-1.5 min-h-[2.25rem] px-3 py-1.5 rounded-md border-2 border-dashed text-[12.5px] font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed hover:bg-black/5"
                :class="[couleurs.border, couleurs.text]"
            >
                <span class="text-[15px] leading-none" aria-hidden="true">＋</span> {{ t.add }}
            </button>
            <span class="text-[11px] text-ink-muted"
                >{{ rows.length }}/{{ max }}<template v-if="complet"> · {{ t.maxReached }}</template></span
            >
            <input ref="entreeAjout" type="file" :accept="accept" class="hidden" @change="onFichierAjoute" />
        </div>

        <p v-if="error" class="text-[11px] text-rose-600 text-start">{{ error }}</p>
    </div>
</template>
