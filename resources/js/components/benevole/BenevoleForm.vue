<!-- resources/js/components/benevole/BenevoleForm.vue -->
<!--
    Formulaire public de candidature bénévole — reconstruit depuis l'ancien
    Google Form (amana_benevoles), voir le prompt de migration du
    24/08/2026 pour le détail du branchement retenu. Même squelette que
    IntakeForm.vue (familles) : consentement → assistant pas-à-pas →
    soumission → attente de confirmation par email. Voir
    BenevoleIntakeController pour le détail du branchement serveur.

    Révisions du 24/08/2026 (retour utilisateur après premier essai) :
      - Bouton "Je refuse" + texte RGPD enrichi (lien EUR-Lex, contact)
        ajoutés à l'étape consentement, manquants dans la V1.
      - Étape véhicule : la capacité (kg) et le nombre de colis ne sont
        plus saisis par le candidat — ce sont des caractéristiques du
        type de véhicule choisi, définies par le staff (voir
        VehiculeTypesController, amana_web_familles) et affichées ici en
        lecture seule à titre indicatif.
      - Étape zone : les secteurs affichent désormais un libellé
        "{Ville} - {Secteur}" (fourni déjà formaté par
        BenevoleIntakeController::showForm) — plusieurs secteurs de villes
        différentes partagent le même nom court (ex. "Centre").
      - Étape disponibilités retirée : fonctionnalité event-related, sera
        réintroduite avec la phase de matching future.
      - Un échec de validation d'étape déclenche maintenant un toast
        (silencieux auparavant — cause la plus probable du "rien ne se
        passe" remonté après le premier essai, notamment sur la dernière
        étape avant soumission).

    Étapes : 0. Consentement RGPD → 1. Identité (composant partagé
    PersonalInfoStep) → 2. Permis → 3. Véhicule → 4. Zone de livraison.
    Étapes 3 et 4 toutes deux masquées si permis === false (30/08/2026,
    voir visibleSteps) : sans permis, ni véhicule ni zone de livraison
    n'ont de sens.
-->
<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useToast, PersonalInfoStep } from '@amana/shared-ui';
import type { PersonalInfoValue } from '@amana/shared-ui';

type Langue = 'fr' | 'ar' | 'en';
type Phase = 'consent' | 'refused' | 'wizard' | 'success';

interface Secteur {
    id: number;
    libelle: string;
}

interface Vehicule {
    id: number;
    type: string;
    capacite_kg: number;
    nombre_part_max: number;
}

const DICT: Record<Langue, Record<string, string>> = {
    fr: {
        nav_next: 'Suivant', nav_prev: 'Précédent',
        nav_submit: 'Envoyer ma candidature', nav_submitting: 'Envoi en cours…',
        step_of: 'Étape {n} sur {total}',
        champ_requis: 'Ce champ est obligatoire.',
        telephone_invalide: 'Numéro de téléphone invalide.',
        validation_error: 'Merci de compléter les champs obligatoires avant de continuer.',

        consent_title: 'Protection des données personnelles',
        consent_intro_html: "Conformément à la législation française et européenne, vos données personnelles sont collectées et traitées dans le respect du <strong>Règlement Général sur la Protection des Données (RGPD)</strong> et de la <strong>loi Informatique et Libertés</strong>.</p><p>Les informations recueillies dans ce formulaire sont utilisées uniquement dans le cadre des activités bénévoles de l'association <strong>AMANA</strong>.</p><p>Vous pouvez consulter l'ensemble des dispositions légales applicables ici :</p><p>👉 <a href=\"https://eur-lex.europa.eu/eli/reg/2016/679/oj\" target=\"_blank\" rel=\"noopener noreferrer\">Texte complet du RGPD sur EUR-Lex</a></p><p>Pour toute question ou pour exercer vos droits (accès, rectification, suppression, opposition, etc.), vous pouvez nous contacter :</p><p>📧 <a href=\"mailto:amana44.benevole@gmail.com\">amana44.benevole@gmail.com</a><br>📱 <strong>WhatsApp</strong> : +33 7 74 83 24 60",
        consent_accept: "J'accepte les termes et conditions concernant la collecte et le traitement de mes données personnelles.",
        consent_refuse: 'Je refuse que mes données personnelles soient collectées et traitées',
        refused_title: 'Refus',
        refused_text: 'En cas de refus, nous ne pourrons pas traiter votre candidature.',

        step_identite: 'Vos informations personnelles',
        nom: 'Nom', prenom: 'Prénom', telephone: 'Téléphone', telephone_hint: 'Format : 0123456789', email: 'Email',
        step_organisation: 'Quelle organisation vous accompagne ?',
        organisation_select: 'Organisation',
        organisation_desc: "Sélectionnez l'organisation à laquelle vous êtes rattaché(e).",

        step_permis: 'Permis de conduire',
        permis_question: 'Avez-vous le permis de conduire ?', oui: 'Oui', non: 'Non',

        step_vehicule: 'Votre véhicule',
        vehicule_question: 'Quel type de véhicule possédez-vous ?',
        vehicule_hint: 'La capacité de charge et le nombre de colis transportables sont définis par notre équipe pour chaque type de véhicule — indiqués ici à titre informatif.',
        vehicule_loading: 'Chargement des types de véhicule…',
        vehicule_empty: "Aucun type de véhicule n'est disponible pour le moment. Merci de réessayer plus tard ou de nous contacter.",
        capacite_kg: 'Capacité indicative', nombre_part_max: 'colis',

        step_zone: 'Zone de livraison',
        zone_question: 'Quel(s) lieu(x) de livraison vous conviennent ?',
        zone_nantes_et_exterieur: 'Je peux livrer à Nantes et en dehors de Nantes',
        zone_nantes_seulement: 'Je ne peux livrer que dans Nantes',
        zone_secteurs_specifiques: 'Je ne peux livrer que dans certains secteurs spécifiques',
        secteurs_hint: 'Sélectionnez un ou plusieurs secteurs',

        success_title: 'Merci pour votre candidature !',
        success_text: "Un email de confirmation vient d'être envoyé à l'adresse indiquée. Merci de cliquer sur le lien qu'il contient dans les 48 heures pour confirmer votre candidature — sans cette confirmation, elle ne sera pas transmise à notre équipe. Pensez à vérifier vos spams si vous ne le recevez pas rapidement.",
        error_generic: 'Une erreur est survenue. Merci de réessayer.',
        error_session_expired: 'Votre session a expiré (formulaire resté ouvert trop longtemps). Merci de recharger la page.',
        error_too_many_attempts: 'Trop de tentatives. Merci de patienter une minute avant de réessayer.',
    },
    ar: {
        nav_next: 'التالي', nav_prev: 'السابق',
        nav_submit: 'إرسال ترشحي', nav_submitting: 'جاري الإرسال…',
        step_of: 'الخطوة {n} من {total}',
        champ_requis: 'هذا الحقل إلزامي.',
        telephone_invalide: 'رقم هاتف غير صالح.',
        validation_error: 'يرجى إكمال الحقول الإلزامية قبل المتابعة.',

        consent_title: 'حماية البيانات الشخصية',
        consent_intro_html: "وفقًا للتشريعات الفرنسية والأوروبية، يتم جمع بياناتكم الشخصية ومعالجتها بما يتوافق مع <strong>اللائحة العامة لحماية البيانات (RGPD)</strong>.</p><p>تُستخدم المعلومات التي يتم جمعها من خلال هذا النموذج فقط في إطار الأنشطة التطوعية لجمعية <strong>AMANA</strong>.</p><p>يمكنكم الاطلاع على جميع الأحكام القانونية ذات الصلة هنا:</p><p>👉 <a href=\"https://eur-lex.europa.eu/eli/reg/2016/679/oj\" target=\"_blank\" rel=\"noopener noreferrer\">النص الكامل للائحة RGPD على موقع EUR-Lex</a></p><p>لأي استفسار أو لممارسة حقوقكم، يُرجى التواصل معنا:</p><p>📧 <a href=\"mailto:amana44.benevole@gmail.com\">amana44.benevole@gmail.com</a><br>📱 <strong>WhatsApp</strong> : +33 7 74 83 24 60",
        consent_accept: 'أوافق على الشروط والأحكام المتعلقة بجمع ومعالجة بياناتي الشخصية',
        consent_refuse: 'أرفض جمع ومعالجة بياناتي الشخصية',
        refused_title: 'الرفض',
        refused_text: 'في حال الرفض، لن نتمكن من معالجة ترشحكم.',

        step_identite: 'معلوماتكم الشخصية',
        nom: 'الاسم', prenom: 'الاسم الأول', telephone: 'الهاتف', telephone_hint: 'الصيغة: 0123456789', email: 'البريد الإلكتروني',
        step_organisation: 'ما هي المنظمة التي ترافقكم؟',
        organisation_select: 'المنظمة',
        organisation_desc: 'يرجى اختيار المنظمة التي أنتم منتسبون إليها.',

        step_permis: 'رخصة القيادة',
        permis_question: 'هل لديكم رخصة قيادة؟', oui: 'نعم', non: 'لا',

        step_vehicule: 'سيارتكم',
        vehicule_question: 'ما نوع السيارة التي تملكونها؟',
        vehicule_hint: 'يتم تحديد الحمولة القصوى وعدد الطرود القابلة للنقل من قبل فريقنا لكل نوع سيارة — معروضة هنا للعلم فقط.',
        vehicule_loading: 'جارٍ تحميل أنواع السيارات…',
        vehicule_empty: 'لا يوجد حاليًا أي نوع سيارة متاح. يرجى المحاولة لاحقًا أو التواصل معنا.',
        capacite_kg: 'الحمولة التقريبية', nombre_part_max: 'طرود',

        step_zone: 'منطقة التوصيل',
        zone_question: 'ما هي أماكن التوصيل المناسبة لكم؟',
        zone_nantes_et_exterieur: 'يمكنني التوصيل داخل وخارج نانت',
        zone_nantes_seulement: 'لا يمكنني التوصيل إلا داخل نانت',
        zone_secteurs_specifiques: 'لا يمكنني التوصيل إلا في قطاعات محددة',
        secteurs_hint: 'اختر قطاعًا واحدًا أو أكثر',

        success_title: 'شكرًا لترشحكم!',
        success_text: 'تم للتو إرسال رسالة تأكيد إلى البريد الإلكتروني الذي قدمتموه. يرجى الضغط على الرابط الموجود فيها خلال 48 ساعة لتأكيد ترشحكم.',
        error_generic: 'حدث خطأ. يرجى المحاولة مرة أخرى.',
        error_session_expired: 'انتهت صلاحية جلستكم. يرجى إعادة تحميل الصفحة.',
        error_too_many_attempts: 'محاولات كثيرة جدًا. يرجى الانتظار قبل إعادة المحاولة.',
    },
    en: {
        nav_next: 'Next', nav_prev: 'Previous',
        nav_submit: 'Submit my application', nav_submitting: 'Sending…',
        step_of: 'Step {n} of {total}',
        champ_requis: 'This field is required.',
        telephone_invalide: 'Invalid phone number.',
        validation_error: 'Please complete the required fields before continuing.',

        consent_title: 'Personal data protection',
        consent_intro_html: "In accordance with French and European legislation, your personal data is collected and processed in compliance with the <strong>General Data Protection Regulation (GDPR)</strong> and the <strong>French Data Protection Act</strong>.</p><p>The information collected in this form is used only within the volunteer activities of the <strong>AMANA</strong> association.</p><p>You can consult all applicable legal provisions here:</p><p>👉 <a href=\"https://eur-lex.europa.eu/eli/reg/2016/679/oj\" target=\"_blank\" rel=\"noopener noreferrer\">Full text of the GDPR on EUR-Lex</a></p><p>For any question or to exercise your rights (access, correction, deletion, objection, etc.), you can contact us:</p><p>📧 <a href=\"mailto:amana44.benevole@gmail.com\">amana44.benevole@gmail.com</a><br>📱 <strong>WhatsApp</strong>: +33 7 74 83 24 60",
        consent_accept: 'I accept the terms regarding the collection and processing of my personal data.',
        consent_refuse: 'I refuse the collection and processing of my personal data',
        refused_title: 'Refusal',
        refused_text: 'If you refuse, we will not be able to process your application.',

        step_identite: 'Your personal information',
        nom: 'Last name', prenom: 'First name', telephone: 'Phone', telephone_hint: 'Format: 0123456789', email: 'Email',
        step_organisation: 'Which organization is supporting you?',
        organisation_select: 'Organization',
        organisation_desc: 'Select the organization you are affiliated with.',

        step_permis: "Driving licence",
        permis_question: 'Do you have a driving licence?', oui: 'Yes', non: 'No',

        step_vehicule: 'Your vehicle',
        vehicule_question: 'What type of vehicle do you have?',
        vehicule_hint: 'Load capacity and the number of parcels you can carry are defined by our team for each vehicle type — shown here for information only.',
        vehicule_loading: 'Loading vehicle types…',
        vehicule_empty: 'No vehicle type is available right now. Please try again later or contact us.',
        capacite_kg: 'Approx. capacity', nombre_part_max: 'parcels',

        step_zone: 'Delivery area',
        zone_question: 'Which delivery locations suit you?',
        zone_nantes_et_exterieur: 'I can deliver in and outside Nantes',
        zone_nantes_seulement: 'I can only deliver within Nantes',
        zone_secteurs_specifiques: 'I can only deliver in specific sectors',
        secteurs_hint: 'Select one or more sectors',

        success_title: 'Thank you for applying!',
        success_text: "A confirmation email has just been sent to the address you provided. Please click the link it contains within 48 hours to confirm your application — without this confirmation, it will not be sent to our team. Please check your spam folder if you don't receive it quickly.",
        error_generic: 'An error occurred. Please try again.',
        error_session_expired: 'Your session has expired (form left open too long). Please reload the page.',
        error_too_many_attempts: 'Too many attempts. Please wait a minute before trying again.',
    },
};

const STEP_IDS = ['identite', 'organisation', 'permis', 'vehicule', 'zone'] as const;
type StepId = typeof STEP_IDS[number];

const toast = useToast();

const langue = ref<Langue>('fr');
const t = computed(() => DICT[langue.value]);

function tr(key: string, vars: Record<string, string | number> = {}): string {
    let s = t.value[key] ?? key;
    Object.entries(vars).forEach(([k, v]) => { s = s.replace(`{${k}}`, String(v)); });
    return s;
}

const storeUrl = ref('');
const refusUrl = ref('');
const secteurs = ref<Secteur[]>([]);
const vehicules = ref<Vehicule[]>([]);
// Chargées via fetch('/vehicules') depuis le 03/09/2026 (remplace le
// data-vehicules embarqué côté Blade) — vehiculesChargement distingue
// "en cours de chargement" de "aucun véhicule configuré" pour ne pas
// afficher t.vehicule_empty pendant le fetch initial.
const vehiculesChargement = ref(true);
const organisations = ref<{ id: number; code: string; nom: string }[]>([]);

const phase = ref<Phase>('consent');
const currentStep = ref(0);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const progressPct = computed(() => {
    const idx = visibleSteps.value.indexOf(currentStepId.value);
    return Math.round(((idx + 1) / visibleSteps.value.length) * 100);
});
const currentStepId = computed<StepId>(() => STEP_IDS[currentStep.value]);

// Étapes "vehicule" ET "zone" masquées si le candidat n'a pas le permis —
// dans ce cas son id_vehicule_type est fixé automatiquement sur l'entrée
// "Sans permis" du référentiel (voir watch sur form.permis plus bas),
// rendant la sélection manuelle inutile. Retour du 24/08/2026 (2e vague) :
// demander malgré tout un type de véhicule à quelqu'un sans permis n'avait
// pas de sens. Même raisonnement appliqué à "zone" le 30/08/2026 : sans
// permis, le candidat ne fera pas de livraisons, donc pas de zone de
// livraison à choisir non plus (form.zone_livraison reste '' dans ce cas,
// voir submit() — le backend n'exige plus le champ que si permis === true).
const visibleSteps = computed<StepId[]>(() =>
    STEP_IDS.filter((s) => (s !== 'vehicule' && s !== 'zone') || form.permis !== false),
);

// "Sans permis" est affecté automatiquement (voir watch sur form.permis
// plus bas) — jamais proposé au choix manuel dans cette liste.
const selectableVehicules = computed<Vehicule[]>(() => vehicules.value.filter((v) => v.type !== 'Sans permis'));

const form = reactive({
    nom: '', prenom: '', email: '', telephone: '', telephone_bis: '',
    id_organisation: null as number | null,
    permis: null as boolean | null,
    id_vehicule_type: null as number | null,
    zone_livraison: '' as '' | 'nantes_et_exterieur' | 'nantes_seulement' | 'secteurs_specifiques',
    secteurs: [] as number[],
    site_web: '', // piège à robots — voir BenevoleIntakeController::store, jamais rempli par un humain
});

// Adaptateur vers le composant partagé PersonalInfoStep (@amana/shared-ui,
// voir aussi IntakeForm.vue qui l'utilise pour la même étape "identite").
const personalInfo = computed<PersonalInfoValue>({
    get: () => ({
        nom: form.nom, prenom: form.prenom,
        telephone: form.telephone, telephone_bis: form.telephone_bis,
        email: form.email,
    }),
    set: (v) => {
        form.nom = v.nom; form.prenom = v.prenom;
        form.telephone = v.telephone; form.telephone_bis = v.telephone_bis ?? '';
        form.email = v.email;
    },
});

// Sans permis → id_vehicule_type fixé sur l'entrée "Sans permis" du
// référentiel, étape véhicule masquée (voir visibleSteps ci-dessus). Un
// retour en arrière vers "Oui" réinitialise le choix pour forcer une
// vraie sélection dans la liste plutôt que de laisser "Sans permis"
// sélectionné par erreur.
watch(() => form.permis, (permis) => {
    if (permis === false) {
        form.id_vehicule_type = vehicules.value.find((v) => v.type === 'Sans permis')?.id ?? null;
    } else if (permis === true && vehicules.value.find((v) => v.id === form.id_vehicule_type)?.type === 'Sans permis') {
        form.id_vehicule_type = null;
    }
});

function toggleInArray<T>(arr: T[], value: T): void {
    const i = arr.indexOf(value);
    if (i === -1) arr.push(value); else arr.splice(i, 1);
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function validateStep(stepId: StepId): boolean {
    errors.value = {};
    const req = (field: string, ok: boolean) => { if (!ok) errors.value[field] = tr('champ_requis'); };
    const telephoneValide = (v: string) => /^[0-9+\s().-]{6,}$/.test(v.trim());

    switch (stepId) {
        case 'identite':
            req('nom', !!form.nom.trim());
            req('prenom', !!form.prenom.trim());
            if (!form.telephone.trim()) errors.value.telephone = tr('champ_requis');
            else if (!telephoneValide(form.telephone)) errors.value.telephone = t.value.telephone_invalide;
            req('email', !!form.email.trim() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()));
            break;
        case 'permis':
            req('permis', form.permis !== null);
            break;
        case 'vehicule':
            req('id_vehicule_type', form.id_vehicule_type !== null);
            break;
        case 'zone':
            // Étape masquée si permis === false (voir visibleSteps) — rien à
            // valider dans ce cas, la question ne se pose pas.
            if (form.permis === false) break;
            req('zone_livraison', !!form.zone_livraison);
            if (form.zone_livraison === 'secteurs_specifiques') {
                req('secteurs', form.secteurs.length > 0);
            }
            break;
    }

    return Object.keys(errors.value).length === 0;
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const data = new FormData();
    const append = (k: string, v: unknown) => { if (v !== null && v !== undefined) data.append(k, String(v)); };

    append('nom', form.nom);
    append('prenom', form.prenom);
    append('email', form.email);
    append('telephone', form.telephone);
    append('langue', langue.value);
    append('id_organisation', form.id_organisation);
    // FormData sérialise tout en string : String(true) donne "true", que la
    // règle Laravel 'boolean' REFUSE (elle n'accepte que true/false/0/1/"0"/"1",
    // pas les chaînes "true"/"false") — d'où le 422 "must be true or false"
    // remonté le 26/08/2026. On envoie 1/0 explicitement.
    append('permis', form.permis ? 1 : 0);
    append('id_vehicule_type', form.id_vehicule_type);
    // Étape "zone" masquée sans permis (voir visibleSteps) : form.zone_livraison
    // reste '' dans ce cas — ne pas l'envoyer du tout plutôt qu'une chaîne
    // vide, qui échouerait la règle 'in:' côté serveur malgré 'nullable'
    // (nullable ne s'applique qu'à une valeur absente/null, pas à '').
    if (form.permis !== false) append('zone_livraison', form.zone_livraison);
    form.secteurs.forEach((id) => data.append('secteurs[]', String(id)));
    append('consentement', true);
    data.append('site_web', form.site_web);

    try {
        const res = await fetch(storeUrl.value, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: data,
        });

        if (res.status === 422) {
            const body = await res.json();
            errors.value = Object.fromEntries(
                Object.entries(body.errors as Record<string, string[]>).map(([k, v]) => [k.replace(/\.\d+$/, ''), v[0]]),
            );
            toast.error(t.value.error_generic);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        if (res.status === 419) {
            toast.error(t.value.error_session_expired);
            return;
        }
        if (res.status === 429) {
            toast.error(t.value.error_too_many_attempts);
            return;
        }
        if (!res.ok) {
            // eslint-disable-next-line no-console
            console.error('Benevole submit failed', res.status, await res.text().catch(() => ''));
            throw new Error(`http_${res.status}`);
        }

        phase.value = 'success';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        // eslint-disable-next-line no-console
        console.error('Benevole submit error', e);
        toast.error(t.value.error_generic);
    } finally {
        submitting.value = false;
    }
}

function next(): void {
    if (!validateStep(currentStepId.value)) {
        toast.error(t.value.validation_error);
        return;
    }
    const steps = visibleSteps.value;
    const idx = steps.indexOf(currentStepId.value);
    if (idx < steps.length - 1) {
        currentStep.value = STEP_IDS.indexOf(steps[idx + 1]);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        submit();
    }
}

function prev(): void {
    const steps = visibleSteps.value;
    const idx = steps.indexOf(currentStepId.value);
    if (idx > 0) {
        currentStep.value = STEP_IDS.indexOf(steps[idx - 1]);
        errors.value = {};
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function onConsentAccept(): void {
    phase.value = 'wizard';
    currentStep.value = 0;
}

async function onConsentRefuse(): Promise<void> {
    try {
        await fetch(refusUrl.value, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ langue: langue.value }),
        });
    } catch {
        // Le refus doit rester silencieux pour le candidat même si la
        // journalisation échoue côté serveur — on ne collecte rien de plus
        // dans tous les cas (même raisonnement que IntakeForm.vue, familles).
    } finally {
        phase.value = 'refused';
    }
}

onMounted(() => {
    const el = document.getElementById('vue-benevole-form');
    if (el) {
        const l = el.dataset.langue as Langue;
        if (l && DICT[l]) langue.value = l;
        storeUrl.value = el.dataset.storeUrl ?? '';
        refusUrl.value = el.dataset.refusUrl ?? '';
        try {
            secteurs.value = JSON.parse(el.dataset.secteurs ?? '[]');
        } catch {
            secteurs.value = [];
        }
        try {
            organisations.value = JSON.parse(el.dataset.organisations ?? '[]');
        } catch {
            organisations.value = [];
        }
    }

    fetch('/vehicules', { headers: { Accept: 'application/json' } })
        .then((res) => {
            if (!res.ok) throw new Error(String(res.status));
            return res.json();
        })
        .then((donnees) => {
            vehicules.value = donnees;
        })
        .catch(() => {
            // Traité comme "aucun véhicule disponible" côté template
            // (t.vehicule_empty invite déjà à réessayer/nous contacter,
            // ce qui reste le bon message que la liste soit vide ou que
            // le fetch ait échoué) plutôt qu'un état d'erreur distinct.
            vehicules.value = [];
        })
        .finally(() => {
            vehiculesChargement.value = false;
        });
});
</script>

<template>
    <!-- Succès -->
    <div v-if="phase === 'success'" class="bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">
        <div class="text-5xl mb-4">✅</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ t.success_title }}</h1>
        <p class="text-ink-muted text-[14px]">{{ t.success_text }}</p>
    </div>

    <!-- Refus de consentement -->
    <div v-else-if="phase === 'refused'" class="bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">
        <div class="text-5xl mb-4">🔒</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ t.refused_title }}</h1>
        <p class="text-ink-muted text-[14px]">{{ t.refused_text }}</p>
    </div>

    <!-- Étape 0 : consentement RGPD -->
    <div v-else-if="phase === 'consent'" class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 space-y-5">
        <h1 class="font-heading text-lg font-semibold text-ink">{{ t.consent_title }}</h1>
        <div class="text-[13px] text-ink-muted leading-relaxed space-y-2 [&_a]:text-accent [&_a]:underline [&_strong]:text-ink [&_strong]:font-semibold"
             v-html="'<p>' + t.consent_intro_html + '</p>'"></div>
        <div class="space-y-2 pt-2">
            <button type="button" @click="onConsentAccept"
                class="w-full text-left px-4 py-3 border-2 border-accent bg-accent/5 hover:bg-accent/10 rounded-lg text-[13.5px] text-ink transition-colors cursor-pointer">
                {{ t.consent_accept }}
            </button>
            <button type="button" @click="onConsentRefuse"
                class="w-full text-left px-4 py-3 border border-ink-faint hover:bg-surface-2 rounded-lg text-[13.5px] text-ink-muted transition-colors cursor-pointer">
                {{ t.consent_refuse }}
            </button>
        </div>
    </div>

    <!-- Assistant pas-à-pas -->
    <form v-else @submit.prevent="next" class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 space-y-6">
        <!-- Piège à robots : voir BenevoleIntakeController::store. -->
        <input type="text" v-model="form.site_web" name="site_web" tabindex="-1" autocomplete="off"
            class="absolute -left-[9999px] w-px h-px opacity-0 overflow-hidden" aria-hidden="true">

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{{ tr('step_of', { n: visibleSteps.indexOf(currentStepId) + 1, total: visibleSteps.length }) }}</span>
                <span class="text-[11px] text-ink-muted">{{ progressPct }}%</span>
            </div>
            <div class="h-1.5 bg-surface-3 rounded-full overflow-hidden">
                <div class="h-full bg-accent transition-all duration-300" :style="{ width: progressPct + '%' }"></div>
            </div>
        </div>

        <!-- Identité -->
        <section v-if="currentStepId === 'identite'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_identite }}</h2>
            <PersonalInfoStep
                v-model="personalInfo"
                :errors="errors"
                :show-telephone-bis="false"
                :labels="{ nom: t.nom, prenom: t.prenom, telephone: t.telephone, telephoneHint: t.telephone_hint, email: t.email }" />
        </section>

        <!-- Organisation -->
        <section v-else-if="currentStepId === 'organisation'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_organisation }}</h2>
            <p class="text-[12.5px] text-ink-muted mb-3">{{ t.organisation_desc }}</p>
            <label class="block text-xs font-semibold text-ink mb-1">{{ t.organisation_select }} *</label>
            <select v-model="form.id_organisation" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                <option :value="null" disabled>{{ t.organisation_select }}…</option>
                <option v-for="org in organisations" :key="org.id" :value="org.id">{{ org.nom }}</option>
            </select>
            <span v-if="errors.id_organisation" class="block text-[11px] text-rose-600 mt-1">{{ errors.id_organisation }}</span>
        </section>

        <!-- Permis -->
        <section v-else-if="currentStepId === 'permis'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_permis }}</h2>
            <p class="text-[13px] text-ink-muted mb-3">{{ t.permis_question }} *</p>
            <div class="grid grid-cols-2 gap-2">
                <label v-for="opt in [{ v: true, l: t.oui }, { v: false, l: t.non }]" :key="String(opt.v)"
                    class="flex items-center gap-2 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none justify-center"
                    :class="form.permis === opt.v ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="radio" :checked="form.permis === opt.v" @change="form.permis = opt.v" class="w-4 h-4 accent-accent">
                    {{ opt.l }}
                </label>
            </div>
            <span v-if="errors.permis" class="block text-[11px] text-rose-600 mt-1">{{ errors.permis }}</span>
        </section>

        <!-- Véhicule -->
        <section v-else-if="currentStepId === 'vehicule'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_vehicule }}</h2>
            <p class="text-[13px] text-ink-muted mb-1">{{ t.vehicule_question }} *</p>
            <p class="text-[11.5px] text-ink-faint mb-3">{{ t.vehicule_hint }}</p>
            <div v-if="vehiculesChargement" class="text-[12.5px] text-ink-muted px-3 py-2.5">
                {{ t.vehicule_loading }}
            </div>
            <div v-else-if="selectableVehicules.length === 0" class="text-[12.5px] text-rose-600 bg-rose-50 border border-rose-200 rounded-md px-3 py-2.5">
                {{ t.vehicule_empty }}
            </div>
            <div v-else class="grid grid-cols-2 gap-2">
                <label v-for="vehicule in selectableVehicules" :key="vehicule.id"
                    class="flex flex-col gap-0.5 px-3 py-2.5 border rounded-md text-[13px] text-ink cursor-pointer select-none"
                    :class="form.id_vehicule_type === vehicule.id ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <span class="flex items-center gap-2">
                        <input type="radio" :checked="form.id_vehicule_type === vehicule.id" @change="form.id_vehicule_type = vehicule.id" class="w-4 h-4 accent-accent">
                        {{ vehicule.type }}
                    </span>
                    <span v-if="vehicule.capacite_kg > 0" class="text-[11px] text-ink-faint pl-6">
                        {{ t.capacite_kg }} : {{ vehicule.capacite_kg }}kg · {{ vehicule.nombre_part_max }} {{ t.nombre_part_max }}
                    </span>
                </label>
            </div>
            <span v-if="errors.id_vehicule_type" class="block text-[11px] text-rose-600 mt-1">{{ errors.id_vehicule_type }}</span>
        </section>

        <!-- Zone de livraison -->
        <section v-else-if="currentStepId === 'zone'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_zone }}</h2>
            <p class="text-[13px] text-ink-muted mb-3">{{ t.zone_question }} *</p>
            <div class="space-y-2 mb-3">
                <label v-for="zone in (['nantes_et_exterieur', 'nantes_seulement', 'secteurs_specifiques'] as const)" :key="zone"
                    class="flex items-center gap-2 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                    :class="form.zone_livraison === zone ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="radio" :checked="form.zone_livraison === zone" @change="form.zone_livraison = zone" class="w-4 h-4 accent-accent">
                    {{ t[`zone_${zone}`] }}
                </label>
            </div>
            <span v-if="errors.zone_livraison" class="block text-[11px] text-rose-600 mb-3">{{ errors.zone_livraison }}</span>

            <template v-if="form.zone_livraison === 'secteurs_specifiques'">
                <p class="text-[11.5px] text-ink-muted mb-2">{{ t.secteurs_hint }}</p>
                <div class="grid grid-cols-2 gap-2 max-h-64 overflow-y-auto pr-1">
                    <label v-for="secteur in secteurs" :key="secteur.id"
                        class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none"
                        :class="form.secteurs.includes(secteur.id) ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                        <input type="checkbox" :checked="form.secteurs.includes(secteur.id)"
                            @change="toggleInArray(form.secteurs, secteur.id)" class="w-4 h-4 accent-accent">
                        {{ secteur.libelle }}
                    </label>
                </div>
                <span v-if="errors.secteurs" class="block text-[11px] text-rose-600 mt-1">{{ errors.secteurs }}</span>
            </template>
        </section>

        <!-- Navigation -->
        <div class="flex gap-3 pt-2">
            <button v-if="currentStep > 0" type="button" @click="prev" :disabled="submitting"
                class="flex-1 min-h-[46px] px-4 py-3 border border-ink-faint hover:bg-surface-2 disabled:opacity-50 text-ink font-semibold text-[14px] rounded-lg transition-colors cursor-pointer">
                {{ t.nav_prev }}
            </button>
            <button type="submit" :disabled="submitting"
                class="flex-1 min-h-[46px] px-6 py-3 bg-accent hover:bg-accent-dark disabled:bg-ink-faint disabled:hover:bg-ink-faint disabled:cursor-not-allowed text-white font-bold text-[14px] rounded-lg
                        shadow-[0_3px_14px_rgba(180,83,9,0.3)] disabled:shadow-none transition-all cursor-pointer">
                {{ visibleSteps.indexOf(currentStepId) === visibleSteps.length - 1 ? (submitting ? t.nav_submitting : t.nav_submit) : t.nav_next }}
            </button>
        </div>
    </form>
</template>
