<!-- resources/js/components/familles/DetailPanel.vue -->
<!--
    Panneau de détail/édition d'un dossier famille (section 8.2 du prompt de
    migration) : tous les champs éditables par admin et gestionnaire,
    organisés en onglets (Identité / Adresse / Situation / Documents) +
    consultation/upload des documents.

    S'ouvre au clic sur une ligne du tableau (resources/views/familles/
    index.blade.php et nouvelles.blade.php, onclick="openFamilleDetail(id)")
    — la fonction globale est exposée par ce composant lui-même à son
    montage, pas par le Blade, pour rester cohérent avec le pattern "Blade en
    coquille, Vue pour l'interactif" du reste de l'app.

    Réutilise <Modal> (shared) pour le backdrop/focus/Escape — pas de
    logique de fenêtre modale dupliquée ici.

    Passage en onglets le 12/08/2026 (remplace le long scroll unique
    d'origine) : le formulaire a grossi avec l'ajout des champs
    hébergement/pièce d'identité/activité (jusqu'ici saisis à l'intake mais
    invisibles/non-éditables ici), et l'adresse gagne une carte + un widget
    de recherche Google — un seul scroll aurait été peu praticable.
-->
<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { Modal } from '@amana/shared-ui';
import { useToast } from '@amana/shared-ui';
import { useConfirm } from '@amana/shared-ui';

declare global {
    interface Window {
        google?: any;
        __googleMapsLoadPromise?: Promise<void>;
    }
}

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

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
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
    est_hotel: boolean;
    etudiant: boolean;
    // Cast decimal:7 côté modèle → sérialisé en chaîne (évite la perte de
    // précision/notation scientifique d'un float JSON) — voir Famille::$casts.
    latitude: string | null;
    longitude: string | null;
    circonstances: string | null;
    ressentit: string | null;
    specificites: string | null;
    criticite: number;
    langue: string;
    etat_dossier: string;
    commentaire_dossier: string | null;
    probleme_traitement: string | null;
    type_hebergement: 'organisation' | 'proche' | 'non' | null;
    hosted_by: string | null;
    type_piece_identite: 'nationalite' | 'titre_sejour' | 'demande_asile' | 'autre' | null;
    type_activite: 'temps_plein' | 'temps_partiel' | 'non' | null;
    work_days: number | null;
    secteur_activite_autre: string | null;
    organisme_aide_autre: string | null;
    // Relations belongsToMany, sérialisées en snake_case par Eloquent —
    // voir Famille::secteursActivite()/organismesAide() et
    // FamillesController::show()/update().
    secteurs_activite: ListeOption[];
    organismes_aide: ListeOption[];
    documents: Document[];
}

type TabId = 'identite' | 'adresse' | 'situation' | 'decision' | 'documents';

const TABS: { id: TabId; label: string; icon: string }[] = [
    { id: 'identite', label: 'Identité', icon: '👤' },
    { id: 'adresse', label: 'Adresse', icon: '📍' },
    { id: 'situation', label: 'Situation', icon: '🗂️' },
    { id: 'decision', label: 'Décision', icon: '🔒' },
    { id: 'documents', label: 'Documents', icon: '📎' },
];

// Champs → onglet, pour afficher un point rouge sur l'onglet contenant une
// erreur de validation (422) sans que le staff ait à deviner où chercher —
// amélioration du 12/08/2026, les erreurs étaient auparavant invisibles si
// l'onglet correspondant n'était pas déjà affiché.
const CHAMPS_PAR_ONGLET: Record<TabId, string[]> = {
    identite: ['nom', 'prenom', 'telephone', 'telephone_bis', 'email'],
    adresse: ['adresse', 'code_postal', 'ville_texte', 'type_hebergement', 'hosted_by'],
    situation: ['type_activite', 'work_days', 'secteurs_activite', 'organismes_aide'],
    // "Décision" : réservé au staff (statut du dossier, criticité, et le
    // reste des observations internes) — regroupé le 12/08/2026, jusqu'ici
    // dispersé entre "Identité" (Éligibilité) et "Situation" (le reste).
    decision: ['criticite', 'etat_dossier'],
    documents: ['type_piece_identite'],
};

// 'Recu' exclu : réservé aux nouvelles soumissions du formulaire public
// (voir Famille::ETATS_MODIFIABLES côté backend, qui rejette toute
// tentative de le sélectionner ici) — décision du 09/08/2026.
const ETATS = ['En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé'];
// Couleurs de badge par statut — repère visuel rapide dans l'en-tête,
// cohérent avec la teinte utilisée pour les mêmes statuts dans le tableau
// principal (familles/index.blade.php).
const COULEURS_ETAT: Record<string, string> = {
    'En cours': 'bg-sky-100 text-sky-700',
    'En attente': 'bg-amber-100 text-amber-700',
    'Validé': 'bg-emerald-100 text-emerald-700',
    'Rejeté': 'bg-rose-100 text-rose-700',
    'Archivé': 'bg-surface-3 text-ink-muted',
};
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
const TYPES_HEBERGEMENT = [
    { code: 'organisation', label: 'Hébergé(e) par une organisation' },
    { code: 'proche', label: 'Hébergé(e) par un proche' },
    { code: 'non', label: 'Non hébergé(e)' },
] as const;
const TYPES_PIECE_IDENTITE = [
    { code: 'nationalite', label: 'Pièce de nationalité' },
    { code: 'titre_sejour', label: 'Titre de séjour' },
    { code: 'demande_asile', label: "Demande d'asile" },
    { code: 'autre', label: 'Autre' },
] as const;
const TYPES_ACTIVITE = [
    { code: 'temps_plein', label: 'Temps plein' },
    { code: 'temps_partiel', label: 'Temps partiel' },
    { code: 'non', label: "N'exerce pas d'activité" },
] as const;

const toast = useToast();
const confirmDialog = useConfirm();

const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const famille = ref<Famille | null>(null);
const errors = ref<Record<string, string>>({});
const activeTab = ref<TabId>('identite');

// Sélections multi (secteurs d'activité / organismes d'aide) : listes
// d'identifiants distinctes de famille.value.secteurs_activite (qui contient
// les objets complets renvoyés par l'API) — reconstruites à l'ouverture,
// envoyées telles quelles au PUT (voir enregistrer()), synchronisées côté
// serveur avec sync() (FamillesController::update()).
const secteursSelectionnes = ref<number[]>([]);
const organismesSelectionnes = ref<number[]>([]);
const secteursActiviteDisponibles = ref<ListeOption[]>([]);
const organismesAideDisponibles = ref<ListeOption[]>([]);

function libelle(option: ListeOption): string {
    return option.libelle_fr;
}

function toggleInArray(arr: number[], id: number): void {
    const i = arr.indexOf(id);
    if (i === -1) arr.push(id); else arr.splice(i, 1);
}

const uploadType = ref('identity');
const uploadFile = ref<File | null>(null);
const uploading = ref(false);

// URL templates (avec placeholders __ID__/__DOC__) + clés/listes injectées
// par Blade via les data-attributes du point de montage — voir
// familles/index.blade.php et familles/nouvelles.blade.php (même panneau
// monté sur les deux vues).
let urls = {
    show: '',
    update: '',
    upload: '',
    download: '',
    deleteDoc: '',
};
const googlePlacesKey = ref('');
const googleEmbedKey = ref('');

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function openFamilleDetail(id: number): Promise<void> {
    open.value = true;
    loading.value = true;
    errors.value = {};
    famille.value = null;
    activeTab.value = 'identite';
    // Recherche Google repart toujours masquée à l'ouverture d'un dossier
    // existant : la famille a déjà une adresse enregistrée, la voir
    // directement en saisie manuelle évite de perdre le texte existant
    // pendant qu'on décide de le corriger ou non — voir manualAdresseMode.
    manualAdresseMode.value = true;
    autocompleteElement = null;

    try {
        const res = await fetch(urls.show.replace('__ID__', String(id)));
        if (!res.ok) throw new Error('Chargement impossible');
        famille.value = await res.json();
        secteursSelectionnes.value = famille.value?.secteurs_activite?.map((s) => s.id) ?? [];
        organismesSelectionnes.value = famille.value?.organismes_aide?.map((o) => o.id) ?? [];
        // Photo de l'adresse au chargement — comparée à la volée dans
        // adresseModifieeDepuisChargement (voir plus bas) pour ne pas
        // afficher un quartier résolu qui ne correspond plus au texte
        // actuellement saisi (ex : juste après une sélection Google Places,
        // avant tout enregistrement — le quartier stocké est encore celui
        // de l'ancienne adresse, la résolution ne se relance que côté
        // serveur après save()).
        adresseInitiale.adresse = famille.value?.adresse ?? '';
        adresseInitiale.code_postal = famille.value?.code_postal ?? '';
        adresseInitiale.ville_texte = famille.value?.ville_texte ?? '';
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

// Même palette et même règle de sélection (id % taille palette) que
// familles/index.blade.php (avatarPalette) — dupliquée ici plutôt que
// partagée via amana_shared_ui pour l'instant (une seule autre
// consommatrice, un composant Blade+PHP ne peut de toute façon pas
// importer un module TS partagé) : garder les deux en synchronisation
// manuelle si la palette change côté liste.
const AVATAR_PALETTE = [
    { bg: 'bg-sky-100', text: 'text-sky-700' },
    { bg: 'bg-amber-100', text: 'text-amber-700' },
    { bg: 'bg-emerald-100', text: 'text-emerald-700' },
    { bg: 'bg-violet-100', text: 'text-violet-700' },
    { bg: 'bg-rose-100', text: 'text-rose-700' },
    { bg: 'bg-cyan-100', text: 'text-cyan-700' },
];
const avatarStyle = computed(() => {
    if (!famille.value) return AVATAR_PALETTE[0];
    return AVATAR_PALETTE[famille.value.id % AVATAR_PALETTE.length];
});
const initiales = computed(() => {
    if (!famille.value) return '';
    return ((famille.value.prenom?.[0] ?? '') + (famille.value.nom?.[0] ?? '')).toUpperCase();
});

function ongletEnErreur(tab: TabId): boolean {
    return CHAMPS_PAR_ONGLET[tab].some((champ) => champ in errors.value);
}

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
            // secteurs_activite/organismes_aide écrasés explicitement :
            // famille.value contient les objets complets renvoyés par
            // l'API (voir interface Famille), le backend attend des
            // tableaux d'identifiants (FamillesController::update()).
            body: JSON.stringify({
                ...famille.value,
                secteurs_activite: secteursSelectionnes.value,
                organismes_aide: organismesSelectionnes.value,
            }),
        });

        if (res.status === 422) {
            const data = await res.json();
            errors.value = Object.fromEntries(
                Object.entries(data.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]]),
            );
            // Bascule automatiquement sur le premier onglet en erreur —
            // sinon un onglet fermé masquerait complètement le retour
            // d'erreur (ex : erreur sur type_hebergement pendant que
            // l'onglet Documents est affiché).
            const premierOngletEnErreur = TABS.find((t) => ongletEnErreur(t.id));
            if (premierOngletEnErreur) activeTab.value = premierOngletEnErreur.id;
            toast.error('Merci de corriger les champs en erreur.');
            return;
        }

        if (!res.ok) throw new Error('Échec de l\'enregistrement');

        famille.value = await res.json();
        secteursSelectionnes.value = famille.value?.secteurs_activite?.map((s) => s.id) ?? [];
        organismesSelectionnes.value = famille.value?.organismes_aide?.map((o) => o.id) ?? [];
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

// Même branchement que IntakeForm.vue::typeDocumentAide : le type de pièce
// d'identité choisi détermine quel justificatif de ressources est requis
// (nationalité/titre de séjour/demande d'asile → CAF, autre → AME) — voir
// Famille::type_document_aide côté backend pour la même règle appliquée à
// l'intake initial.
const typeDocumentAide = computed<'caf' | 'ame' | null>(() => {
    if (!famille.value?.type_piece_identite) return null;
    return famille.value.type_piece_identite === 'autre' ? 'ame' : 'caf';
});

// Identité et Ressources sont toujours pertinents (indépendants du choix
// ci-dessus) ; CAF/AME n'apparaît que pour celui correspondant à
// typeDocumentAide — sauf s'il existe déjà des documents de l'AUTRE type
// (dossier créé avant ce champ, ou type corrigé après coup) : dans ce cas
// on ne les cache jamais, un admin doit toujours voir/pouvoir gérer les
// fichiers déjà présents quel que soit le réglage actuel.
const typesDocumentsAffiches = computed(() => {
    return TYPES_DOCUMENTS.filter((t) => {
        if (t.code === 'identity' || t.code === 'resource') return true;
        if (t.code === typeDocumentAide.value) return true;
        return documentsParType(t.code).length > 0;
    });
});

// Garde-fou : si le type actuellement sélectionné dans le menu d'envoi
// disparaît de typesDocumentsAffiches (ex : changement de type de pièce
// d'identité alors que "AME" était choisi et n'a aucun document existant),
// on retombe sur "identity" plutôt que de laisser une valeur invisible
// sélectionnée en arrière-plan.
watch(typesDocumentsAffiches, (types) => {
    if (!types.some((t) => t.code === uploadType.value)) {
        uploadType.value = 'identity';
    }
});

// ── Autocomplétion d'adresse (Google Places, PlaceAutocompleteElement) ──
// Même widget que IntakeForm.vue (google.maps.places.Autocomplete legacy
// bloqué pour ce projet GCP depuis mars 2025 — voir commentaire détaillé
// là-bas). Différence avec l'intake : ici l'adresse existe déjà la plupart
// du temps, donc la saisie manuelle (pré-remplie) est affichée par défaut,
// avec un bouton pour ouvrir la recherche Google si le staff veut la
// corriger — inverse du comportement à l'intake (recherche affichée en
// premier, formulaire encore vide).
const placeAutocompleteContainerRef = ref<HTMLDivElement | null>(null);
let autocompleteElement: any = null;
const manualAdresseMode = ref(true);

// Capture de l'adresse au chargement (voir openFamilleDetail) — sert à
// détecter si l'adresse a été changée dans la session en cours (saisie
// manuelle ou sélection Google) sans encore avoir été enregistrée : dans
// ce cas famille.quartier (résolu côté serveur pour l'ANCIENNE adresse)
// n'est plus fiable, corrigé le 12/08/2026 après retour "le quartier
// résolu affiché pointe encore vers l'ancienne adresse après une sélection
// Google Places".
const adresseInitiale = { adresse: '', code_postal: '', ville_texte: '' };
const adresseModifieeDepuisChargement = computed<boolean>(() => {
    if (!famille.value) return false;
    return famille.value.adresse !== adresseInitiale.adresse
        || (famille.value.code_postal ?? '') !== adresseInitiale.code_postal
        || (famille.value.ville_texte ?? '') !== adresseInitiale.ville_texte;
});

function loadGoogleMapsScript(apiKey: string): Promise<void> {
    if (window.__googleMapsLoadPromise) return window.__googleMapsLoadPromise;

    window.__googleMapsLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        // v=weekly : canal recommandé par Google pour importLibrary(), voir
        // https://developers.google.com/maps/documentation/javascript/versions
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&language=fr`;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('google_maps_load_failed'));
        document.head.appendChild(script);
    });

    return window.__googleMapsLoadPromise;
}

function extraireComposant(place: any, type: string): string {
    const c = (place.addressComponents ?? []).find((c: any) => c.types.includes(type));
    return c ? c.longText : '';
}

async function initAdresseAutocomplete(): Promise<void> {
    if (!googlePlacesKey.value || !placeAutocompleteContainerRef.value || autocompleteElement) return;

    try {
        await loadGoogleMapsScript(googlePlacesKey.value);
        const { PlaceAutocompleteElement } = await window.google.maps.importLibrary('places');

        autocompleteElement = new PlaceAutocompleteElement({
            includedRegionCodes: ['fr'],
            requestedLanguage: 'fr',
        });
        // Sans ceci, le widget adopte le thème sombre du navigateur/OS et
        // s'affiche comme une barre noire illisible (même correctif que
        // IntakeForm.vue — voir commentaire là-bas pour la source
        // documentée du contournement).
        autocompleteElement.style.width = '100%';
        autocompleteElement.style.setProperty('color-scheme', 'light');
        autocompleteElement.style.setProperty('background-color', '#ffffff');
        autocompleteElement.style.setProperty('border', '1px solid #d6d3d1');
        autocompleteElement.style.setProperty('border-radius', '6px');
        placeAutocompleteContainerRef.value.appendChild(autocompleteElement);

        autocompleteElement.addEventListener('gmp-select', async ({ placePrediction }: any) => {
            if (!famille.value) return;
            const place = placePrediction.toPlace();
            await place.fetchFields({ fields: ['addressComponents', 'formattedAddress'] });

            const numero = extraireComposant(place, 'street_number');
            const rue = extraireComposant(place, 'route');
            famille.value.adresse = [numero, rue].filter(Boolean).join(' ') || place.formattedAddress || famille.value.adresse;

            const codePostal = extraireComposant(place, 'postal_code');
            if (codePostal) famille.value.code_postal = codePostal;

            const ville = extraireComposant(place, 'locality') || extraireComposant(place, 'postal_town');
            if (ville) famille.value.ville_texte = ville;

            // Révèle le champ texte (pré-rempli) pour relecture/correction,
            // même logique que IntakeForm.vue.
            manualAdresseMode.value = true;
        });
    } catch {
        manualAdresseMode.value = true;
    }
}

async function ouvrirRechercheGoogle(): Promise<void> {
    manualAdresseMode.value = false;
    autocompleteElement = null;
    await nextTick();
    await initAdresseAutocomplete();
}

// Le conteneur de la suggestion n'existe dans le DOM que lorsque l'onglet
// Adresse est actif et manualAdresseMode === false — pas d'init tant que le
// staff n'a pas explicitement demandé la recherche Google (voir
// ouvrirRechercheGoogle ci-dessus, contrairement à IntakeForm.vue qui
// initialise dès l'affichage de son étape adresse).

// ── Carte de l'adresse (Google Maps Embed API) ───────────────────────────
// Toujours en mode "place" (jamais "view") : c'est le seul mode de
// l'Embed API qui affiche une punaise — "view" ne fait que centrer/zoomer
// sans aucun repère visuel, ce qui rendait la carte peu lisible (retour du
// 12/08/2026). "place" accepte q=lat,lng aussi bien qu'une adresse texte
// (voir https://developers.google.com/maps/documentation/embed/embed-api-modes#place-mode),
// donc les deux branches ci-dessous utilisent le même mode — seul q change
// selon que les coordonnées géocodées sont disponibles ou non. Accessoirement
// corrige aussi le lien "Affichage d'une carte plus grande" intégré par
// Google dans l'iframe : en mode "view" il ne pointait que sur des
// coordonnées nues (aucune adresse à afficher côté Google Maps une fois le
// lien ouvert) ; en mode "place" il pointe sur le même repère qu'affiché
// ici, adresse comprise.
const mapEmbedUrl = computed<string | null>(() => {
    if (!googleEmbedKey.value || !famille.value) return null;

    if (famille.value.latitude && famille.value.longitude) {
        return `https://www.google.com/maps/embed/v1/place?key=${encodeURIComponent(googleEmbedKey.value)}&q=${famille.value.latitude},${famille.value.longitude}&zoom=16`;
    }

    const adresseComplete = [famille.value.adresse, famille.value.code_postal, famille.value.ville_texte]
        .filter(Boolean)
        .join(', ');
    if (!adresseComplete.trim()) return null;

    return `https://www.google.com/maps/embed/v1/place?key=${encodeURIComponent(googleEmbedKey.value)}&q=${encodeURIComponent(adresseComplete)}`;
});

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
        googlePlacesKey.value = el.dataset.googlePlacesKey ?? '';
        googleEmbedKey.value = el.dataset.googleEmbedKey ?? '';
        try {
            secteursActiviteDisponibles.value = JSON.parse(el.dataset.secteursActivite ?? '[]');
            organismesAideDisponibles.value = JSON.parse(el.dataset.organismesAide ?? '[]');
        } catch {
            secteursActiviteDisponibles.value = [];
            organismesAideDisponibles.value = [];
        }
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
                <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0" :class="[avatarStyle.bg, avatarStyle.text]">
                    {{ initiales }}
                </div>
                <div>
                    <h2 class="font-heading text-base font-semibold text-ink">{{ famille.prenom }} {{ famille.nom }}</h2>
                    <p class="text-[12px] text-ink-muted flex items-center gap-1.5">
                        Dossier #{{ famille.id }} · {{ nombreFoyer }} personne{{ nombreFoyer !== 1 ? 's' : '' }} au foyer
                        <span class="px-1.5 py-0.5 rounded text-[10.5px] font-semibold" :class="COULEURS_ETAT[famille.etat_dossier] ?? 'bg-surface-3 text-ink-muted'">
                            {{ famille.etat_dossier }}
                        </span>
                    </p>
                </div>
            </div>
            <span v-else class="font-heading text-base font-semibold text-ink">Chargement…</span>
        </template>

        <div v-if="loading" class="py-16 text-center text-ink-muted text-[13.5px]">Chargement du dossier…</div>

        <form v-else-if="famille" @submit.prevent="enregistrer" class="space-y-4">

            <!-- Barre d'onglets -->
            <div class="flex gap-1 border-b border-surface-3 -mt-1 mb-1" role="tablist">
                <button v-for="tab in TABS" :key="tab.id" type="button" role="tab"
                    :aria-selected="activeTab === tab.id"
                    @click="activeTab = tab.id"
                    class="relative flex items-center gap-1.5 px-3 py-2.5 text-[12.5px] font-semibold rounded-t-md transition-colors cursor-pointer min-h-[40px]"
                    :class="activeTab === tab.id
                        ? 'text-accent border-b-2 border-accent -mb-px'
                        : 'text-ink-muted hover:text-ink hover:bg-surface-2'">
                    <span aria-hidden="true">{{ tab.icon }}</span>
                    {{ tab.label }}
                    <span v-if="tab.id === 'documents' && famille.documents.length"
                        class="px-1.5 py-0.5 rounded-full bg-surface-3 text-ink-muted text-[10px] font-bold">
                        {{ famille.documents.length }}
                    </span>
                    <span v-if="ongletEnErreur(tab.id)"
                        class="absolute top-1.5 right-0.5 w-1.5 h-1.5 rounded-full bg-rose-500" aria-hidden="true"></span>
                </button>
            </div>

            <!-- ── Onglet Identité ─────────────────────────────────────── -->
            <div v-show="activeTab === 'identite'" class="space-y-4">
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">👤</span> Identité &amp; contact
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Prénom</label>
                            <input v-model="famille.prenom" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.prenom" class="text-[11px] text-rose-600">{{ errors.prenom }}</span>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Nom</label>
                            <input v-model="famille.nom" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.nom" class="text-[11px] text-rose-600">{{ errors.nom }}</span>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Téléphone</label>
                            <input v-model="famille.telephone" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.telephone" class="text-[11px] text-rose-600">{{ errors.telephone }}</span>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Téléphone (bis)</label>
                            <input v-model="famille.telephone_bis" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-ink mb-1">Email</label>
                            <input v-model="famille.email" type="email" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.email" class="text-[11px] text-rose-600">{{ errors.email }}</span>
                        </div>
                    </div>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">👨‍👩‍👧‍👦</span> Composition du foyer
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Adultes</label>
                            <input v-model.number="famille.nombre_adulte" type="number" min="0" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Enfants</label>
                            <input v-model.number="famille.nombre_enfant" type="number" min="0" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                        </div>
                        <label class="col-span-2 flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                            :class="famille.etudiant ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                            <input v-model="famille.etudiant" type="checkbox" class="w-4 h-4 accent-accent">
                            🎓 Étudiant(e)
                        </label>
                    </div>
                </section>
            </div>

            <!-- ── Onglet Adresse ──────────────────────────────────────── -->
            <div v-show="activeTab === 'adresse'" class="space-y-4">
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🏠</span> Adresse
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Adresse</label>

                            <!-- Recherche Google : masquée par défaut (voir
                                 manualAdresseMode), ouverte via le bouton
                                 ci-dessous. -->
                            <div v-if="googlePlacesKey && !manualAdresseMode" ref="placeAutocompleteContainerRef" class="mb-2"></div>
                            <button v-if="googlePlacesKey && !manualAdresseMode" type="button" @click="manualAdresseMode = true"
                                class="text-[12px] text-accent underline mb-2 cursor-pointer bg-transparent border-0">
                                Revenir à la saisie manuelle
                            </button>

                            <textarea v-if="!googlePlacesKey || manualAdresseMode" v-model="famille.adresse" rows="2"
                                class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none resize-none transition-colors"></textarea>
                            <button v-if="googlePlacesKey && manualAdresseMode" type="button" @click="ouvrirRechercheGoogle"
                                class="text-[12px] text-accent underline mt-1 cursor-pointer bg-transparent border-0">
                                🔍 Rechercher une nouvelle adresse via Google
                            </button>
                            <span v-if="errors.adresse" class="text-[11px] text-rose-600">{{ errors.adresse }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-ink mb-1">Code postal</label>
                                <input v-model="famille.code_postal" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-ink mb-1">Ville (saisie)</label>
                                <input v-model="famille.ville_texte" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            </div>
                        </div>

                        <!-- Adresse modifiée depuis le chargement (saisie
                             manuelle ou sélection Google) et pas encore
                             enregistrée : le quartier ci-dessous, résolu
                             côté serveur, correspond encore à l'ANCIENNE
                             adresse — on l'indique plutôt que d'afficher un
                             nom de quartier qui ne correspond plus à ce qui
                             est saisi (voir adresseModifieeDepuisChargement). -->
                        <p v-if="adresseModifieeDepuisChargement" class="text-[12px] text-amber-700">
                            📍 Quartier non à jour — sera recalculé automatiquement à l'enregistrement.
                        </p>
                        <p v-else-if="famille.quartier" class="text-[12px] text-ink-muted">
                            📍 Quartier résolu : <strong>{{ famille.quartier.nom }}</strong>
                            <span class="text-ink-faint">(résolution géographique automatique, non modifiable ici)</span>
                        </p>
                    </div>
                </section>

                <div v-if="famille.probleme_traitement" class="flex items-start gap-2 px-3 py-2.5 rounded-lg bg-rose-50 border border-rose-200 text-[12.5px] text-rose-700">
                    <span>⚠️</span>
                    <span>{{ famille.probleme_traitement }}
                        <span class="block text-[11px] text-rose-500 mt-0.5">Corrigez l'adresse ci-dessus et enregistrez pour relancer la résolution automatique.</span>
                    </span>
                </div>

                <!-- Hébergement : regroupe tout ce qui décrit la situation
                     de logement actuelle de la famille (déplacé depuis
                     l'onglet Situation le 12/08/2026, plus cohérent avec
                     "Adresse" qu'avec le reste du suivi de dossier). -->
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🛏️</span> Hébergement
                    </h3>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                                :class="famille.se_deplace ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                                <input v-model="famille.se_deplace" type="checkbox" class="w-4 h-4 accent-accent">
                                Peut se déplacer
                            </label>
                            <label class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                                :class="famille.est_hotel ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                                <input v-model="famille.est_hotel" type="checkbox" class="w-4 h-4 accent-accent">
                                🏨 Hébergement en hôtel (urgence)
                            </label>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1.5">Type d'hébergement</label>
                            <div class="space-y-1.5">
                                <label v-for="opt in TYPES_HEBERGEMENT" :key="opt.code"
                                    class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                                    :class="famille.type_hebergement === opt.code ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                                    <input type="radio" name="type_hebergement" :value="opt.code" v-model="famille.type_hebergement" class="w-4 h-4 accent-accent">
                                    {{ opt.label }}
                                </label>
                            </div>
                            <span v-if="errors.type_hebergement" class="text-[11px] text-rose-600">{{ errors.type_hebergement }}</span>
                        </div>
                        <div v-if="famille.type_hebergement === 'organisation'">
                            <label class="block text-xs font-semibold text-ink mb-1">Hébergé par (nom de l'organisation)</label>
                            <input v-model="famille.hosted_by" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.hosted_by" class="text-[11px] text-rose-600">{{ errors.hosted_by }}</span>
                        </div>
                    </div>
                </section>

                <!-- Carte : déplacée en bas de l'onglet le 12/08/2026 (les
                     champs éditables passent avant, la carte est une
                     confirmation visuelle en dernier lieu). Présente
                     seulement si une clé Embed API est configurée ET qu'on
                     a de quoi la centrer (coordonnées ou adresse texte) —
                     voir mapEmbedUrl. Toujours en mode "place" : affiche
                     une punaise sur l'adresse (contrairement au mode "view"
                     précédemment utilisé pour les coordonnées géocodées,
                     qui ne montrait qu'un centre de carte sans aucun repère
                     — voir le commentaire sur mapEmbedUrl). -->
                <section v-if="mapEmbedUrl" class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🗺️</span> Carte
                        <span v-if="!famille.latitude || !famille.longitude" class="normal-case font-normal text-ink-faint">
                            (approximative — adresse pas encore géolocalisée)
                        </span>
                    </h3>
                    <iframe
                        :src="mapEmbedUrl"
                        class="w-full h-56 rounded-lg border border-surface-border"
                        style="border:0"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Carte de l'adresse de la famille">
                    </iframe>
                </section>
            </div>

            <!-- ── Onglet Situation ────────────────────────────────────── -->
            <div v-show="activeTab === 'situation'" class="space-y-4">
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">📋</span> Suivi du dossier
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Langue</label>
                            <select v-model="famille.langue" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                                <option v-for="l in LANGUES" :key="l.code" :value="l.code">{{ l.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Circonstances</label>
                            <textarea v-model="famille.circonstances" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none resize-none transition-colors"></textarea>
                        </div>
                    </div>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">💼</span> Activité professionnelle
                    </h3>
                    <div class="space-y-3">
                        <div class="space-y-1.5">
                            <label v-for="opt in TYPES_ACTIVITE" :key="opt.code"
                                class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                                :class="famille.type_activite === opt.code ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                                <input type="radio" name="type_activite" :value="opt.code" v-model="famille.type_activite" class="w-4 h-4 accent-accent">
                                {{ opt.label }}
                            </label>
                            <span v-if="errors.type_activite" class="block text-[11px] text-rose-600">{{ errors.type_activite }}</span>
                        </div>

                        <div v-if="famille.type_activite === 'temps_partiel'">
                            <label class="block text-xs font-semibold text-ink mb-1">Jours travaillés / semaine</label>
                            <input v-model.number="famille.work_days" type="number" min="0" max="4" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                            <span v-if="errors.work_days" class="text-[11px] text-rose-600">{{ errors.work_days }}</span>
                        </div>

                        <div v-if="famille.type_activite === 'temps_plein' || famille.type_activite === 'temps_partiel'">
                            <label class="block text-xs font-semibold text-ink mb-1.5">Secteur d'activité</label>
                            <div class="grid grid-cols-2 gap-1.5">
                                <label v-for="secteur in secteursActiviteDisponibles" :key="secteur.id"
                                    class="flex items-center gap-2 px-2.5 py-1.5 border rounded-md text-[12.5px] text-ink cursor-pointer select-none transition-colors"
                                    :class="secteursSelectionnes.includes(secteur.id) ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                                    <input type="checkbox" :checked="secteursSelectionnes.includes(secteur.id)"
                                        @change="toggleInArray(secteursSelectionnes, secteur.id)" class="w-4 h-4 accent-accent">
                                    {{ libelle(secteur) }}
                                </label>
                            </div>
                            <input v-model="famille.secteur_activite_autre" type="text" placeholder="Autre secteur (préciser)"
                                class="w-full mt-2 px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent transition-colors">
                            <span v-if="errors.secteurs_activite" class="block text-[11px] text-rose-600">{{ errors.secteurs_activite }}</span>
                        </div>
                    </div>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🤝</span> Aides d'autres organismes
                    </h3>
                    <div class="grid grid-cols-2 gap-1.5">
                        <label v-for="organisme in organismesAideDisponibles" :key="organisme.id"
                            class="flex items-center gap-2 px-2.5 py-1.5 border rounded-md text-[12.5px] text-ink cursor-pointer select-none transition-colors"
                            :class="organismesSelectionnes.includes(organisme.id) ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                            <input type="checkbox" :checked="organismesSelectionnes.includes(organisme.id)"
                                @change="toggleInArray(organismesSelectionnes, organisme.id)" class="w-4 h-4 accent-accent">
                            {{ libelle(organisme) }}
                        </label>
                    </div>
                    <input v-model="famille.organisme_aide_autre" type="text" placeholder="Autre organisme (préciser)"
                        class="w-full mt-2 px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent transition-colors">
                </section>
            </div>

            <!-- ── Onglet Décision (staff uniquement) ──────────────────── -->
            <!-- Regroupe le 12/08/2026 les champs d'appréciation interne —
                 jusqu'ici dispersés entre "Identité" (Éligibilité) et
                 "Situation" (le reste) — dans un onglet dédié, distinct du
                 reste du dossier qui décrit la situation déclarée par la
                 famille elle-même plutôt que l'appréciation du staff. -->
            <div v-show="activeTab === 'decision'" class="space-y-4">
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🕌</span> Éligibilité
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                            :class="famille.zakat_el_fitr ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                            <input v-model="famille.zakat_el_fitr" type="checkbox" class="w-4 h-4 accent-accent">
                            Éligible Zakat El Fitr
                        </label>
                        <label class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none transition-colors"
                            :class="famille.sadaqa ? 'border-accent bg-accent/5' : 'border-ink-faint bg-surface'">
                            <input v-model="famille.sadaqa" type="checkbox" class="w-4 h-4 accent-accent">
                            Éligible Sadaqa
                        </label>
                    </div>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🚦</span> Statut &amp; criticité
                    </h3>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-ink mb-1">Criticité (0-5)</label>
                                <input v-model.number="famille.criticite" type="number" min="0" max="5" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                                <div class="flex gap-1 mt-1.5" aria-hidden="true">
                                    <span v-for="n in 5" :key="n" class="w-4 h-1.5 rounded-full"
                                        :class="n <= famille.criticite ? (famille.criticite >= 4 ? 'bg-rose-500' : 'bg-amber-400') : 'bg-surface-3'"></span>
                                </div>
                                <span v-if="errors.criticite" class="text-[11px] text-rose-600">{{ errors.criticite }}</span>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-ink mb-1">Statut du dossier</label>
                                <select v-model="famille.etat_dossier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                                    <option v-for="e in ETATS" :key="e" :value="e">{{ e }}</option>
                                </select>
                                <span v-if="errors.etat_dossier" class="text-[11px] text-rose-600">{{ errors.etat_dossier }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">📝</span> Observations internes
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Ressenti</label>
                            <textarea v-model="famille.ressentit" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none resize-none transition-colors"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Spécificités</label>
                            <textarea v-model="famille.specificites" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none resize-none transition-colors"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink mb-1">Commentaire (interne)</label>
                            <textarea v-model="famille.commentaire_dossier" rows="2" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none resize-none transition-colors"></textarea>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ── Onglet Documents ────────────────────────────────────── -->
            <div v-show="activeTab === 'documents'" class="space-y-4">
                <!-- Le type de pièce d'identité pilote quel justificatif est
                     requis (CAF ou AME) — même logique de branchement que
                     IntakeForm.vue (voir typeDocumentAide). Déplacé ici
                     depuis l'onglet Situation le 12/08/2026 : n'a de sens
                     qu'au regard des documents à fournir, pas comme donnée
                     de suivi de dossier isolée. -->
                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">🪪</span> Type de pièce d'identité
                    </h3>
                    <select v-model="famille.type_piece_identite" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface focus:border-accent outline-none transition-colors">
                        <option :value="null">—</option>
                        <option v-for="opt in TYPES_PIECE_IDENTITE" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
                    </select>
                    <span v-if="errors.type_piece_identite" class="block text-[11px] text-rose-600 mt-1">{{ errors.type_piece_identite }}</span>
                    <p v-if="typeDocumentAide" class="text-[11.5px] text-ink-muted mt-2">
                        Justificatif requis en conséquence : <strong>{{ typeDocumentAide === 'ame' ? "Aide médicale de l'État (AME)" : 'Attestation CAF' }}</strong>
                    </p>
                    <p v-else class="text-[11.5px] text-ink-faint mt-2">
                        Sélectionnez un type ci-dessus pour voir quel justificatif (CAF ou AME) est requis.
                    </p>
                </section>

                <section class="bg-surface-2 rounded-xl border border-surface-border p-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-muted mb-3">
                        <span aria-hidden="true">📎</span> Documents
                    </h3>

                    <!-- typesDocumentsAffiches : Identité et Ressources sont
                         toujours pertinents ; CAF/AME n'apparaît que pour
                         celui correspondant au choix ci-dessus — sauf s'il
                         existe déjà des documents de l'autre type (dossier
                         plus ancien / correction historique), auquel cas on
                         ne les cache jamais. -->
                    <div v-for="t in typesDocumentsAffiches" :key="t.code" class="mb-3">
                        <p class="text-[12px] font-semibold text-ink-muted mb-1.5 flex items-center gap-1.5">
                            {{ t.label }}
                            <span v-if="t.code === typeDocumentAide" class="px-1.5 py-0.5 rounded-full bg-accent/10 text-accent text-[10px] font-bold">Requis</span>
                        </p>
                        <ul v-if="documentsParType(t.code).length" class="space-y-1.5 mb-2">
                            <li v-for="doc in documentsParType(t.code)" :key="doc.id"
                                class="flex items-center justify-between gap-2 px-3 py-2 bg-surface rounded-md text-[12.5px] border border-surface-border">
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
                        <select v-model="uploadType" class="px-2.5 py-2 border border-ink-faint rounded-md text-[12.5px] bg-surface outline-none">
                            <option v-for="t in typesDocumentsAffiches" :key="t.code" :value="t.code">{{ t.label }}</option>
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
            </div>

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
