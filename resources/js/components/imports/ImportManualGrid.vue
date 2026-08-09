<!-- resources/js/components/imports/ImportManualGrid.vue -->
<!--
    Saisie manuelle de plusieurs dossiers d'un coup (décision 6.9) — envoie
    un tableau de lignes au MÊME pipeline serveur que l'upload CSV
    (FamilleImportService::traiterLigne), via admin.imports.store-manuel.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useToast } from '@amana/shared-ui';

interface Ligne {
    nom: string;
    prenom: string;
    telephone: string;
    email: string;
    adresse: string;
    code_postal: string;
    ville_texte: string;
    nombre_adulte: string;
    nombre_enfant: string;
    zakat_el_fitr: boolean;
    sadaqa: boolean;
    se_deplace: boolean;
}

function ligneVide(): Ligne {
    return {
        nom: '', prenom: '', telephone: '', email: '',
        adresse: '', code_postal: '', ville_texte: '',
        nombre_adulte: '1', nombre_enfant: '0',
        zakat_el_fitr: false, sadaqa: false, se_deplace: false,
    };
}

const toast = useToast();
const storeUrl = ref('');
const submitting = ref(false);
const lignes = ref<Ligne[]>([ligneVide(), ligneVide(), ligneVide()]);

function ajouterLigne(): void {
    lignes.value.push(ligneVide());
}

function supprimerLigne(index: number): void {
    lignes.value.splice(index, 1);
    if (lignes.value.length === 0) ajouterLigne();
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function envoyer(): Promise<void> {
    // Ne garder que les lignes où au moins nom OU prénom a été saisi —
    // évite d'envoyer des lignes vides laissées par défaut dans la grille.
    const lignesUtiles = lignes.value.filter((l) => l.nom.trim() || l.prenom.trim());

    if (lignesUtiles.length === 0) {
        toast.error('Aucune ligne à importer — renseignez au moins le nom ou le prénom.');
        return;
    }

    submitting.value = true;
    document.getElementById('import-overlay')?.classList.remove('hidden');
    try {
        const res = await fetch(storeUrl.value, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ lignes: lignesUtiles }),
        });

        if (!res.ok) throw new Error();

        const data = await res.json();
        toast.success('Import terminé.');
        window.location.href = data.redirect;
    } catch (e) {
        toast.error('Échec de l\'import.');
        document.getElementById('import-overlay')?.classList.add('hidden');
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    const el = document.getElementById('vue-import-manual-grid');
    if (el) storeUrl.value = el.dataset.storeUrl ?? '';
});
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-x-auto -mx-1">
            <table class="w-full border-collapse text-[12.5px] min-w-[900px]">
                <thead>
                    <tr>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Prénom</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Nom</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Téléphone</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Email</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Adresse</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-20">CP</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase">Ville</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-14">Ad.</th>
                        <th class="text-left px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-14">Enf.</th>
                        <th class="text-center px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-10">ZF</th>
                        <th class="text-center px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-10">SA</th>
                        <th class="text-center px-2 py-2 text-[10px] font-bold text-ink-muted uppercase w-14">Dépl.</th>
                        <th class="w-8"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(ligne, i) in lignes" :key="i" class="border-b border-surface-3">
                        <td class="p-1"><input v-model="ligne.prenom" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.nom" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.telephone" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.email" type="email" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.adresse" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.code_postal" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.ville_texte" type="text" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.nombre_adulte" type="number" min="0" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1"><input v-model="ligne.nombre_enfant" type="number" min="0" class="w-full px-2 py-1.5 border border-ink-faint rounded text-[12.5px] bg-surface-2 outline-none focus:border-accent"></td>
                        <td class="p-1 text-center"><input v-model="ligne.zakat_el_fitr" type="checkbox" class="w-4 h-4 accent-accent"></td>
                        <td class="p-1 text-center"><input v-model="ligne.sadaqa" type="checkbox" class="w-4 h-4 accent-accent"></td>
                        <td class="p-1 text-center"><input v-model="ligne.se_deplace" type="checkbox" class="w-4 h-4 accent-accent"></td>
                        <td class="p-1 text-center">
                            <button type="button" @click="supprimerLigne(i)"
                                class="text-rose-400 hover:text-rose-600 bg-transparent border-0 cursor-pointer text-sm min-h-[32px] min-w-[32px]">✕</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between pt-2">
            <button type="button" @click="ajouterLigne"
                class="px-3 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-md transition-colors cursor-pointer">
                + Ajouter une ligne
            </button>
            <button type="button" @click="envoyer" :disabled="submitting"
                class="px-5 py-2.5 bg-accent hover:bg-accent-dark disabled:opacity-50 text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                {{ submitting ? 'Import en cours…' : '📥 Importer ces dossiers' }}
            </button>
        </div>
    </div>
</template>
