<!-- resources/js/components/familles/DetailPanel.vue -->
<!--
    Panneau de détail/édition d'un dossier famille (section 8.2 du prompt de
    migration) : tous les champs éditables par admin et gestionnaire,
    organisés en groupes logiques, + consultation/upload des documents.

    S'ouvre au clic sur une ligne du tableau (resources/views/familles/
    index.blade.php, onclick="openFamilleDetail(id)") — la fonction globale
    est exposée par ce composant lui-même à son montage, pas par le Blade,
    pour rester cohérent avec le pattern "Blade en coquille, Vue pour
    l'interactif" du reste de l'app.

    Réutilise <Modal> (shared) pour le backdrop/focus/Escape — pas de
    logique de fenêtre modale dupliquée ici.
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Modal } from '@amana/shared-ui';
import { useToast } from '@amana/shared-ui';
import { useConfirm } from '@amana/shared-ui';

interface Quartier {
    id: number;
    nom: string;
}

interface Document {
    id: number;
    type: 'identity' | 'caf' | 'ame' | 'resource';
    original_name: string;
    mime_type: string;
    uploaded_at: string;
}

interface Famille {
    id: number;
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string;
    telephone_bis: string | null;
    zakat_el_fitr: boolean;
    sadaqa: boolean;
    nombre_adulte: number;
    nombre_enfant: number;
    adresse: string;
    code_postal: string | null;
    ville_texte: string | null;
    id_quartier: number | null;
    quartier: Quartier | null;
    se_deplace: boolean;
    circonstances: string | null;
    ressentit: string | null;
    specificites: string | null;
    criticite: number;
    langue: string;
    etat_dossier: string;
    commentaire_dossier: string | null;
    probleme_traitement: string | null;
    documents: Document[];
}

// 'Recu' exclu : réservé aux nouvelles soumissions du formulaire public
// (voir Famille::ETATS_MODIFIABLES côté backend, qui rejette toute
// tentative de le sélectionner ici) — décision du 09/08/2026.
const ETATS = ['En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé'];
const LANGUES = [
    { code: 'fr', label: 'Français' },
    { code: 'ar', label: 'العربية' },
    { code: 'en', label: 'English' },
];
const TYPES_DOCUMENTS = [
    { code: 'identity', label: "Pièce d'identité" },
    { code: 'caf', label: 'Attestation CAF' },
    { code: 'ame', label: "Aide médicale de l'État (AME)" },
    { code: 'resource', label: 'Justificatif de ressources' },
];

const toast = useToast();
const confirmDialog = useConfirm();

const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const famille = ref<Famille | null>(null);
const errors = ref<Record<string, string>>({});

const uploadType = ref('identity');
const uploadFile = ref<File | null>(null);
const uploading = ref(false);

// URL templates (avec placeholders __ID__/__DOC__) injectées par Blade via
// les data-attributes du point de montage — voir familles/index.blade.php.
let urls = {
    show: '',
    update: '',
    upload: '',
    download: '',
    deleteDoc: '',
};

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function openFamilleDetail(id: number): Promise<void> {
    open.value = true;
    loading.value = true;
    errors.value = {};
    famille.value = null;

    try {
        const res = await fetch(urls.show.replace('__ID__', String(id)));
        if (!res.ok) throw new Error('Chargement impossible');
        famille.value = await res.json();
    } catch (e) {
        toast.error('Impossible de charger le dossier.');
        open.value = false;
    } finally {
        loading.value = false;
    }
}

function close(): void {
    open.value = false;
    famille.value = null;
    errors.value = {};
}

const nombreFoyer = computed(() => {
    if (!famille.value) return 0;
    return (famille.value.nombre_adulte || 0) + (famille.value.nombre_enfant || 0);
});

async function enregistrer(): Promise<void> {
    if (!famille.value) return;
    saving.value = true;
    errors.value = {};

    try {
        const res = await fetch(urls.update.replace('__ID__', String(famille.value.id)), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify(famille.value),
        });

        if (res.status === 422) {
            const data = await res.json();
            errors.value = Object.fromEntries(
                Object.entries(data.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]]),
            );
            toast.error('Merci de corriger les champs en erreur.');
            return;
        }

        if (!res.ok) throw new Error('Échec de l\'enregistrement');

        famille.value = await res.json();
        toast.success('Dossier enregistré.');

        // Rafraîchit le tableau sous-jacent (statut/quartier/criticité
        // affichés en liste ont pu changer) — approche simple v1, à
        // remplacer par une mise à jour ciblée de la ligne si besoin.
        setTimeout(() => window.location.reload(), 600);
    } catch (e) {
        toast.error('Erreur réseau — le dossier n\'a pas été enregistré.');
    } finally {
        saving.value = false;
    }
}

async function envoyerDocument(): Promise<void> {
    if (!famille.value || !uploadFile.value) return;
    uploading.value = true;

    const formData = new FormData();
    formData.append('type', uploadType.value);
    formData.append('fichier', uploadFile.value);

    try {
        const res = await fetch(urls.upload.replace('__ID__', String(famille.value.id)), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: formData,
        });

        if (!res.ok) {
            const data = await res.json().catch(() => null);
            throw new Error(data?.message ?? 'Échec de l\'envoi');
        }

        const document: Document = await res.json();
        famille.value.documents.push(document);
        uploadFile.value = null;
        const input = window.document.getElementById('famille-doc-input') as HTMLInputElement | null;
        if (input) input.value = '';
        toast.success('Document ajouté.');
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'Échec de l\'envoi du document.');
    } finally {
        uploading.value = false;
    }
}

async function supprimerDocument(doc: Document): Promise<void> {
    if (!famille.value) return;

    const confirmed = await confirmDialog.ask({
        message: `Supprimer définitivement le document « ${doc.original_name} » ?`,
        danger: true,
    });
    if (!confirmed) return;

    try {
        const res = await fetch(
            urls.deleteDoc.replace('__ID__', String(famille.value.id)).replace('__DOC__', String(doc.id)),
            {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            },
        );
        if (!res.ok) throw new Error();

        famille.value.documents = famille.value.documents.filter((d) => d.id !== doc.id);
        toast.success('Document supprimé.');
    } catch (e) {
        toast.error('Échec de la suppression du document.');
    }
}

function urlTelechargement(doc: Document): string {
    if (!famille.value) return '#';
    return urls.download.replace('__ID__', String(famille.value.id)).replace('__DOC__', String(doc.id));
}

function documentsParType(type: string): Document[] {
    return famille.value?.documents.filter((d) => d.type === type) ?? [];
}

// Même pattern que window.closeSidebar dans MobileSidebar.vue et
// window.toggleAppTheme dans lib/theme.ts : fonction exposée globalement
// pour être appelée depuis un attribut onclick d'un Blade (pas de Vue
// monté sur chaque ligne du tableau, juste ce composant unique).
declare global {
    interface Window {
        openFamilleDetail: (id: number) => void;
    }
}

onMounted(() => {
    const el = document.getElementById('vue-famille-detail');
    if (el) {
        urls = {
            show: el.dataset.showUrlTemplate ?? '',
            update: el.dataset.updateUrlTemplate ?? '',
            upload: el.dataset.uploadUrlTemplate ?? '',
            download: el.dataset.downloadUrlTemplate ?? '',
            deleteDoc: el.dataset.deleteDocUrlTemplate ?? '',
        };
    }

    // Exposée globalement : appelée depuis l'attribut onclick des lignes
    // du tableau Blade (pas de dépendance circulaire Blade→Vue autrement).
    window.openFamilleDetail = openFamilleDetail;
});
</script>

<template>
    <Modal :open="open" max-width="max-w-2xl" @close="close">
        <template #header>
            <div v-if="famille" class="flex items-center gap-3">
                <div class="w-9 h-9 bg-accent rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                    {{ famille.prenom.charAt(0).toUpperCase() }}
                </div>
                <div>
                    <h2 class="font-heading text-base font-semibold text-ink">{{ famille.prenom }} {{ famille.nom }}</h2>
                    <p class="text-[12px] text-ink-muted">Dossier #{{ famille.id }} · {{ nombreFoyer }} personne{{ nombreFoyer !== 1 ? 's' : '' }} au foyer</p>
                </div>
            </div>
            <span v-else class="font-heading text-base font-semibold text-ink">Chargement…</span>
        </template>

        <div v-if="loading" class="py-16 text-center text-ink-muted text-[13.5px]">Chargement du dossier…</div>

        <form v-else-if="famille" @submit.prevent="enregistrer" class="space-y-6">

            <!-- Identité & contact -->
            <section>
                <h3 class="text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-2.5">Identité &amp; contact</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Prénom</label>
                        <input v-model="famille.prenom" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        <span v-if="errors.prenom" class="text-[11px] text-rose-600">{{ errors.prenom }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Nom</label>
                        <input v-model="famille.nom" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        <span v-if="errors.nom" class="text-[11px] text-rose-600">{{ errors.nom }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Téléphone</label>
                        <input v-model="famille.telephone" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        <span v-if="errors.telephone" class="text-[11px] text-rose-600">{{ errors.telephone }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Téléphone (bis)</label>
                        <input v-model="famille.telephone_bis" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-ink mb-1">Email</label>
                        <input v-model="famille.email" type="email" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        <span v-if="errors.email" class="text-[11px] text-rose-600">{{ errors.email }}</span>
                    </div>
                </div>
            </section>

            <!-- Composition du foyer -->
            <section>
                <h3 class="text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-2.5">Composition du foyer</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Adultes</label>
                        <input v-model.number="famille.nombre_adulte" type="number" min="0" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Enfants</label>
                        <input v-model.number="famille.nombre_enfant" type="number" min="0" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                    </div>
                </div>
            </section>

            <!-- Adresse & quartier -->
            <section>
                <h3 class="text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-2.5">Adresse &amp; quartier</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Adresse</label>
                        <textarea v-model="famille.adresse" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none resize-none"></textarea>
                        <span v-if="errors.adresse" class="text-[11px] text-rose-600">{{ errors.adresse }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Code postal</label>
                            <input v-model="famille.code_postal" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Ville (saisie)</label>
                            <input v-model="famille.ville_texte" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                        </div>
                    </div>
                    <p v-if="famille.quartier" class="text-[12px] text-ink-muted">
                        📍 Quartier résolu : <strong>{{ famille.quartier.nom }}</strong>
                        <span class="text-ink-faint">(résolution géographique automatique, non modifiable ici)</span>
                    </p>
                    <label class="flex items-center gap-2 text-[13px] text-ink cursor-pointer select-none">
                        <input v-model="famille.se_deplace" type="checkbox" class="w-4 h-4 accent-accent">
                        La famille peut se déplacer
                    </label>
                </div>
            </section>

            <!-- Situation & aide -->
            <section>
                <h3 class="text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-2.5">Situation &amp; aide</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 text-[13px] text-ink cursor-pointer select-none">
                            <input v-model="famille.zakat_el_fitr" type="checkbox" class="w-4 h-4 accent-accent">
                            Éligible Zakat El Fitr
                        </label>
                        <label class="flex items-center gap-2 text-[13px] text-ink cursor-pointer select-none">
                            <input v-model="famille.sadaqa" type="checkbox" class="w-4 h-4 accent-accent">
                            Éligible Sadaqa
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Criticité (0-5)</label>
                            <input v-model.number="famille.criticite" type="number" min="0" max="5" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                            <span v-if="errors.criticite" class="text-[11px] text-rose-600">{{ errors.criticite }}</span>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Langue</label>
                            <select v-model="famille.langue" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                                <option v-for="l in LANGUES" :key="l.code" :value="l.code">{{ l.label }}</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="famille.probleme_traitement" class="flex items-start gap-2 px-3 py-2.5 rounded-md bg-rose-50 border border-rose-200 text-[12.5px] text-rose-700">
                        <span>⚠️</span>
                        <span>{{ famille.probleme_traitement }}
                            <span class="block text-[11px] text-rose-500 mt-0.5">Corrigez l'adresse ci-dessus et enregistrez pour relancer la résolution automatique.</span>
                        </span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Statut du dossier</label>
                        <select v-model="famille.etat_dossier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none">
                            <option v-for="e in ETATS" :key="e" :value="e">{{ e }}</option>
                        </select>
                        <span v-if="errors.etat_dossier" class="text-[11px] text-rose-600">{{ errors.etat_dossier }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Circonstances</label>
                        <textarea v-model="famille.circonstances" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none resize-none"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Ressenti</label>
                        <textarea v-model="famille.ressentit" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none resize-none"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Spécificités</label>
                        <textarea v-model="famille.specificites" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none resize-none"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">Commentaire (interne)</label>
                        <textarea v-model="famille.commentaire_dossier" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 focus:border-accent outline-none resize-none"></textarea>
                    </div>
                </div>
            </section>

            <!-- Documents -->
            <section>
                <h3 class="text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-2.5">Documents</h3>

                <div v-for="t in TYPES_DOCUMENTS" :key="t.code" class="mb-3">
                    <p class="text-[12px] font-semibold text-ink-muted mb-1.5">{{ t.label }}</p>
                    <ul v-if="documentsParType(t.code).length" class="space-y-1.5 mb-2">
                        <li v-for="doc in documentsParType(t.code)" :key="doc.id"
                            class="flex items-center justify-between gap-2 px-3 py-2 bg-surface-2 rounded-md text-[12.5px]">
                            <a :href="urlTelechargement(doc)" class="text-accent hover:underline truncate flex-1">
                                📄 {{ doc.original_name }}
                            </a>
                            <button type="button" @click="supprimerDocument(doc)"
                                class="text-rose-500 hover:text-rose-700 text-xs bg-transparent border-0 cursor-pointer flex-shrink-0 min-h-[32px] min-w-[32px]">
                                🗑️
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-[11.5px] text-ink-faint mb-2">Aucun document.</p>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-surface-3">
                    <select v-model="uploadType" class="px-2.5 py-2 border border-ink-faint rounded-md text-[12.5px] bg-surface-2 outline-none">
                        <option v-for="t in TYPES_DOCUMENTS" :key="t.code" :value="t.code">{{ t.label }}</option>
                    </select>
                    <input id="famille-doc-input" type="file" accept=".pdf,.jpg,.jpeg,.png"
                        @change="uploadFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        class="flex-1 text-[12px] text-ink-muted">
                    <button type="button" @click="envoyerDocument" :disabled="!uploadFile || uploading"
                        class="px-3 py-2 bg-accent hover:bg-accent-dark disabled:opacity-50 text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer flex-shrink-0">
                        {{ uploading ? 'Envoi…' : 'Ajouter' }}
                    </button>
                </div>
            </section>

        </form>

        <template #footer>
            <button type="button" @click="close"
                class="px-4 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                Fermer
            </button>
            <button type="button" @click="enregistrer" :disabled="saving || loading || !famille"
                class="px-5 py-2 bg-accent hover:bg-accent-dark disabled:opacity-50 text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                {{ saving ? 'Enregistrement…' : '💾 Enregistrer' }}
            </button>
        </template>
    </Modal>
</template>
