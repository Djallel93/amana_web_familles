<!-- resources/js/components/familles/ReverseSyncPanel.vue -->
<!--
    Panneau "Sync retour Google Contacts" — pendant, côté amana_web_familles,
    du dialogue reverseContactSync.html de l'ancien système Google Apps
    Script (amana_familles) : scanne les contacts Google déjà liés à un
    dossier (familles.google_resource_name), compare champ par champ, et
    laisse le staff résoudre chaque écart avant d'appliquer.

    Ouvert depuis familles/index.blade.php (bouton dédié, gestionnaire+)
    via window.openReverseSyncPanel() — même pattern d'exposition globale
    que window.openFamilleDetail dans DetailPanel.vue.

    Trois écrans : intro (explique ce que fait le scan) → loading (spinner
    pendant le scan) → review (résolution famille par famille, avec
    navigation précédent/suivant façon ancien dialogue GAS) → un état final
    récapitulatif après application des décisions.
-->
<script setup lang="ts">
import { ref, computed, reactive, onMounted } from 'vue';
import { Modal } from '@amana/shared-ui';
import { useToast } from '@amana/shared-ui';

type Action = 'accepter_db' | 'accepter_contact' | 'ecraser';
type TypeChamp = 'texte' | 'booleen' | 'enum';

interface EcartChamp {
    champ: string;
    label: string;
    type: TypeChamp;
    valeur_db: string | boolean | null;
    valeur_contact: string | boolean | null;
}

interface DiffFamille {
    id_famille: number;
    nom_complet: string;
    champs: EcartChamp[];
}

type Screen = 'intro' | 'loading' | 'review' | 'done';

const toast = useToast();

const open = ref(false);
const screen = ref<Screen>('intro');
const diffs = ref<DiffFamille[]>([]);
const currentIndex = ref(0);
const applying = ref(false);
const resultatsApplication = ref<{ id_famille: number; succes: boolean; erreur?: string }[]>([]);

// Décisions courantes, indexées par "id_famille:champ" — reactive() plutôt
// que ref<Record<...>> imbriqué : simplifie les mutations ponctuelles depuis
// le template (v-model direct sur decisions[cle].action / .valeur).
interface Decision {
    action: Action;
    valeur: string;
}
const decisions = reactive<Record<string, Decision>>({});

let urls = { scan: '', appliquer: '' };

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function cleChamp(idFamille: number, champ: string): string {
    return `${idFamille}:${champ}`;
}

function texteValeur(valeur: string | boolean | null): string {
    if (valeur === null || valeur === '') return '—';
    if (typeof valeur === 'boolean') return valeur ? 'Oui' : 'Non';
    return valeur;
}

const familleActuelle = computed<DiffFamille | null>(() => diffs.value[currentIndex.value] ?? null);

function decisionsFamille(famille: DiffFamille): Decision[] {
    return famille.champs.map((c) => decisions[cleChamp(famille.id_famille, c.champ)]);
}

function statutFamille(famille: DiffFamille): 'pending' | 'partial' | 'done' {
    const decs = decisionsFamille(famille);
    const nbEcrasesSansValeur = decs.filter((d) => d.action === 'ecraser' && !d.valeur.trim()).length;
    if (nbEcrasesSansValeur > 0) return 'partial';
    return 'done';
}

async function ouvrir(): Promise<void> {
    open.value = true;
    screen.value = 'intro';
    diffs.value = [];
    currentIndex.value = 0;
    resultatsApplication.value = [];
}

async function demarrerScan(): Promise<void> {
    screen.value = 'loading';

    try {
        const res = await fetch(urls.scan, { headers: { Accept: 'application/json' } });
        const data = await res.json();

        if (!res.ok) {
            toast.error(data.error ?? "Échec du scan des contacts Google.");
            open.value = false;
            return;
        }

        diffs.value = data.diffs as DiffFamille[];

        // Valeur par défaut sûre pour chaque champ en écart : on garde la
        // valeur actuelle du dossier (accepter_db) tant que le staff n'a
        // pas explicitement choisi autre chose — évite d'écraser une
        // donnée DB par erreur en cas de clic hâtif sur "Appliquer".
        diffs.value.forEach((famille) => {
            famille.champs.forEach((c) => {
                decisions[cleChamp(famille.id_famille, c.champ)] = { action: 'accepter_db', valeur: '' };
            });
        });

        // Familles dont le contact Google n'existait plus (resourceName
        // périmé) — le backend les a déjà détachées (google_resource_name
        // remis à null, un contact neuf sera recréé à la prochaine
        // transition Validé/Rejeté/Archivé) : on informe simplement le
        // staff plutôt que de les faire disparaître sans explication.
        const introuvables = (data.introuvables ?? []) as { id_famille: number; nom_complet: string }[];
        if (introuvables.length > 0) {
            const noms = introuvables.map((f) => f.nom_complet).join(', ');
            toast.error(
                `${introuvables.length} contact(s) Google introuvable(s), dossier(s) détaché(s) (recréation automatique au prochain changement de statut) : ${noms}`
            );
        }

        if (diffs.value.length === 0) {
            if (introuvables.length === 0) {
                toast.success('Aucun écart détecté — les contacts Google sont déjà alignés avec les dossiers.');
            }
            open.value = false;
            return;
        }

        currentIndex.value = 0;
        screen.value = 'review';
    } catch (e) {
        toast.error('Erreur réseau pendant le scan.');
        open.value = false;
    }
}

function precedent(): void {
    if (currentIndex.value > 0) currentIndex.value--;
}

function suivant(): void {
    if (currentIndex.value < diffs.value.length - 1) currentIndex.value++;
}

async function appliquer(): Promise<void> {
    const familleIncomplete = diffs.value.find((f) => statutFamille(f) === 'partial');
    if (familleIncomplete) {
        toast.error(`Merci de saisir une valeur pour chaque champ "Écraser" — voir ${familleIncomplete.nom_complet}.`);
        currentIndex.value = diffs.value.indexOf(familleIncomplete);
        return;
    }

    applying.value = true;

    const payload = {
        decisions: diffs.value.map((famille) => ({
            id_famille: famille.id_famille,
            champs: famille.champs.map((c) => {
                const d = decisions[cleChamp(famille.id_famille, c.champ)];
                return {
                    champ: c.champ,
                    action: d.action,
                    valeur: d.action === 'ecraser' ? d.valeur : null,
                    valeur_contact: c.valeur_contact,
                };
            }),
        })),
    };

    try {
        const res = await fetch(urls.appliquer, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json();
        if (!res.ok) {
            toast.error(data.error ?? "Échec de l'application des décisions.");
            applying.value = false;
            return;
        }

        resultatsApplication.value = data.resultats;
        screen.value = 'done';

        const nbEchecs = resultatsApplication.value.filter((r) => !r.succes).length;
        if (nbEchecs === 0) {
            toast.success(`${resultatsApplication.value.length} dossier(s) synchronisé(s) avec succès.`);
        } else {
            toast.error(`${nbEchecs} dossier(s) en échec — voir le détail.`);
        }
    } catch (e) {
        toast.error("Erreur réseau pendant l'application des décisions.");
    } finally {
        applying.value = false;
    }
}

function fermer(): void {
    open.value = false;

    // Le tableau sous-jacent (statut/quartier peuvent avoir changé pour
    // les dossiers mis à jour depuis Google) n'est rafraîchi qu'à la
    // fermeture, pas à chaque décision — même approche "simple v1" que
    // DetailPanel.vue::enregistrer().
    if (screen.value === 'done' && resultatsApplication.value.length > 0) {
        window.location.reload();
    }
}

// Exposition globale — voir familles/index.blade.php (bouton) et le
// point de montage #vue-reverse-sync-panel (data-scan-url/data-apply-url).
declare global {
    interface Window {
        openReverseSyncPanel?: () => void;
    }
}

onMounted(() => {
    const el = document.getElementById('vue-reverse-sync-panel');
    if (el) {
        urls = {
            scan: el.dataset.scanUrl ?? '',
            appliquer: el.dataset.applyUrl ?? '',
        };
    }

    window.openReverseSyncPanel = ouvrir;
});
</script>

<template>
    <Modal :open="open" max-width="max-w-3xl" @close="fermer">
        <template #header>
            <h2 class="text-[15px] font-bold text-ink">🔄 Sync retour Google Contacts</h2>
        </template>

        <!-- ── Écran intro ─────────────────────────────────────────────── -->
        <div v-if="screen === 'intro'" class="space-y-4">
            <div class="rounded-lg bg-accent/5 border border-accent/20 px-4 py-3.5 text-[13px] text-ink leading-relaxed">
                Ce panneau compare chaque dossier famille lié à un contact Google
                (téléphone, email, adresse, étudiant, hôtel, statut) avec la
                dernière version enregistrée dans Google Contacts, et vous laisse
                choisir, champ par champ, quelle version garder en cas d'écart.
                <ul class="list-disc pl-5 mt-2 space-y-1">
                    <li><strong>Garder le dossier</strong> : la valeur actuelle du dossier est repoussée vers Google.</li>
                    <li><strong>Accepter Google</strong> : la valeur lue dans Google Contacts est enregistrée dans le dossier.</li>
                    <li><strong>Écraser</strong> : vous saisissez une nouvelle valeur, appliquée aux deux côtés.</li>
                </ul>
            </div>
        </div>

        <!-- ── Écran chargement ────────────────────────────────────────── -->
        <div v-else-if="screen === 'loading'" class="flex flex-col items-center justify-center gap-4 py-10">
            <div class="w-11 h-11 border-4 border-surface-3 border-t-accent rounded-full animate-spin"></div>
            <p class="text-[13px] text-ink-muted">Lecture des contacts Google et comparaison avec les dossiers…</p>
        </div>

        <!-- ── Écran résolution ────────────────────────────────────────── -->
        <div v-else-if="screen === 'review' && familleActuelle" class="space-y-4">
            <div class="flex items-center gap-2">
                <button type="button" class="px-2.5 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 disabled:opacity-40 disabled:cursor-not-allowed text-ink text-[11.5px] font-semibold rounded-md transition-colors cursor-pointer" :disabled="currentIndex === 0" @click="precedent">← Précédent</button>
                <span class="flex-1 text-[13.5px] font-bold text-accent-dark truncate">{{ familleActuelle.nom_complet }}</span>
                <span class="text-[11.5px] text-ink-muted whitespace-nowrap">{{ currentIndex + 1 }} / {{ diffs.length }}</span>
                <button type="button" class="px-2.5 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 disabled:opacity-40 disabled:cursor-not-allowed text-ink text-[11.5px] font-semibold rounded-md transition-colors cursor-pointer" :disabled="currentIndex === diffs.length - 1" @click="suivant">Suivant →</button>
            </div>

            <table class="w-full text-[12.5px] border border-surface-border rounded-lg overflow-hidden">
                <thead>
                    <tr class="bg-surface-2 text-[10.5px] uppercase tracking-wide text-ink-muted">
                        <th class="text-left px-3 py-2">Champ</th>
                        <th class="text-left px-3 py-2">Dossier</th>
                        <th class="text-left px-3 py-2">Google</th>
                        <th class="text-left px-3 py-2">Décision</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in familleActuelle.champs" :key="c.champ" class="border-t border-surface-3">
                        <td class="px-3 py-2 font-semibold text-ink">{{ c.label }}</td>
                        <td class="px-3 py-2 text-accent-dark">{{ texteValeur(c.valeur_db) }}</td>
                        <td class="px-3 py-2 text-rose-600">{{ texteValeur(c.valeur_contact) }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-col gap-1.5">
                                <div class="flex gap-1.5">
                                    <button type="button"
                                        class="px-2 py-1 rounded text-[11px] font-semibold border transition-colors cursor-pointer whitespace-nowrap"
                                        :class="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action === 'accepter_db' ? 'bg-accent border-accent text-white' : 'bg-surface border-surface-border text-ink hover:bg-surface-2'"
                                        @click="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action = 'accepter_db'">
                                        Garder dossier
                                    </button>
                                    <button type="button"
                                        class="px-2 py-1 rounded text-[11px] font-semibold border transition-colors cursor-pointer whitespace-nowrap"
                                        :class="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action === 'accepter_contact' ? 'bg-accent border-accent text-white' : 'bg-surface border-surface-border text-ink hover:bg-surface-2'"
                                        @click="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action = 'accepter_contact'">
                                        Accepter Google
                                    </button>
                                    <button type="button"
                                        class="px-2 py-1 rounded text-[11px] font-semibold border transition-colors cursor-pointer whitespace-nowrap"
                                        :class="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action === 'ecraser' ? 'bg-accent border-accent text-white' : 'bg-surface border-surface-border text-ink hover:bg-surface-2'"
                                        @click="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action = 'ecraser'">
                                        Écraser
                                    </button>
                                </div>
                                <input
                                    v-if="decisions[cleChamp(familleActuelle.id_famille, c.champ)].action === 'ecraser'"
                                    v-model="decisions[cleChamp(familleActuelle.id_famille, c.champ)].valeur"
                                    type="text"
                                    placeholder="Nouvelle valeur…"
                                    class="w-full text-[12px] px-2 py-1 rounded border border-surface-border" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex flex-wrap gap-1.5">
                <span v-for="(f, i) in diffs" :key="f.id_famille"
                    class="w-2.5 h-2.5 rounded-full cursor-pointer"
                    :class="[
                        i === currentIndex ? 'ring-2 ring-accent' : '',
                        statutFamille(f) === 'done' ? 'bg-emerald-400' : 'bg-amber-400',
                    ]"
                    :title="f.nom_complet"
                    @click="currentIndex = i">
                </span>
            </div>
        </div>

        <!-- ── Écran récapitulatif ─────────────────────────────────────── -->
        <div v-else-if="screen === 'done'" class="space-y-2">
            <p class="text-[13px] text-ink-muted mb-2">
                {{ resultatsApplication.filter(r => r.succes).length }} / {{ resultatsApplication.length }} dossier(s) synchronisé(s) avec succès.
            </p>
            <ul class="text-[12.5px] space-y-1 max-h-64 overflow-y-auto">
                <li v-for="r in resultatsApplication" :key="r.id_famille"
                    :class="r.succes ? 'text-emerald-700' : 'text-rose-700'">
                    Dossier #{{ r.id_famille }} — {{ r.succes ? 'OK' : ('Échec : ' + r.erreur) }}
                </li>
            </ul>
        </div>

        <template #footer>
            <template v-if="screen === 'intro'">
                <button type="button" @click="fermer"
                    class="px-4 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Annuler
                </button>
                <button type="button" @click="demarrerScan"
                    class="px-5 py-2 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Lancer le scan
                </button>
            </template>
            <template v-else-if="screen === 'review'">
                <button type="button" @click="fermer" :disabled="applying"
                    class="px-4 py-2 border border-surface-border bg-surface hover:bg-surface-2 disabled:opacity-50 text-ink text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Annuler
                </button>
                <button type="button" :disabled="applying" @click="appliquer"
                    class="px-5 py-2 bg-accent hover:bg-accent-dark disabled:opacity-50 text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    {{ applying ? 'Application…' : `Appliquer les décisions (${diffs.length})` }}
                </button>
            </template>
            <template v-else-if="screen === 'done'">
                <button type="button" @click="fermer"
                    class="px-5 py-2 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Fermer
                </button>
            </template>
        </template>
    </Modal>
</template>
