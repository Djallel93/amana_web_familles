<!-- resources/js/components/intake/IntakeForm.vue -->
<!--
    Formulaire public de demande d'aide — assistant pas-à-pas qui reproduit
    le branchement du Google Form historique de amana_familles
    (formulaire_famille_fr/en/ar.json, section 8.2 du prompt de migration).
    Voir le contrôleur IntakeController pour le détail du branchement et
    IntakeController::refuserConsentement() pour le cas de refus RGPD.

    Étapes (dans l'ordre du Google Form) :
      0. Consentement RGPD — refus = fin immédiate, rien d'autre n'est
         collecté (section "Refus" du formulaire d'origine).
      1. Identité
      2. Hébergement (organisation/proche/non — "par qui" seulement si
         organisation)
      3. Adresse actuelle
      4. Situation familiale
      5. Situation administrative (type de pièce d'identité → justificatif
         CAF ou AME selon le choix)
      6. Activité professionnelle (temps plein/partiel/non — jours/semaine
         seulement si partiel, secteur si plein OU partiel)
      7. Ressources (aides d'autres organismes, justificatifs optionnels)

    i18n minimal : un seul dictionnaire de libellés par langue (FR/AR/EN),
    pas de librairie i18n. La direction RTL est gérée par le
    <html dir="rtl"> côté Blade, pas ici.
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch, nextTick } from 'vue';
import { useToast } from '@amana/shared-ui';

declare global {
    interface Window {
        google?: any;
        __googleMapsLoadPromise?: Promise<void>;
    }
}

type Langue = 'fr' | 'ar' | 'en';
type Phase = 'consent' | 'wizard' | 'refused' | 'success';

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
}

const DICT: Record<Langue, Record<string, string>> = {
    fr: {
        nav_next: 'Suivant', nav_prev: 'Précédent',
        nav_submit: 'Envoyer ma demande', nav_submitting: 'Envoi en cours…',
        step_of: 'Étape {n} sur {total}',
        champ_requis: 'Ce champ est obligatoire.',

        consent_title: 'Protection des données personnelles',
        consent_intro_html: "Conformément à la législation française et européenne, vos données personnelles sont collectées et traitées dans le respect du <strong>Règlement Général sur la Protection des Données (RGPD)</strong> et de la <strong>loi Informatique et Libertés</strong>.</p><p>Les informations recueillies dans ce formulaire sont utilisées uniquement dans le cadre des activités de l'association <strong>AMANA</strong>, pour la gestion et le suivi de votre dossier.</p><p>Vous pouvez consulter l'ensemble des dispositions légales applicables ici :</p><p>👉 <a href=\"https://eur-lex.europa.eu/eli/reg/2016/679/oj\" target=\"_blank\" rel=\"noopener noreferrer\">Texte complet du RGPD sur EUR-Lex</a></p><p>Pour toute question ou pour exercer vos droits (accès, rectification, suppression, opposition, etc.), vous pouvez nous contacter :</p><p>📧 <a href=\"mailto:amana44.pole.social@gmail.com\">amana44.pole.social@gmail.com</a><br>📱 <strong>WhatsApp</strong> : +33 7 74 83 24 60",
        consent_accept: "J'accepte les termes et conditions concernant la collecte et le traitement de mes données personnelles.",
        consent_refuse: 'Je refuse que mes données personnelles soient collectées et traitées',
        consent_continue: 'Continuer',

        refused_title: 'Refus',
        refused_text: 'En cas de refus, nous ne pourrons pas traiter votre demande.',

        step_identite: 'Informations personnelles',
        nom: 'Nom de famille', prenom: 'Prénom de la personne à contacter',
        email: 'Email',
        telephone: 'Numéro de téléphone de la personne à contacter',
        telephone_hint: 'Format : 0123456789',
        telephone_invalide: 'Numéro de téléphone invalide.',
        telephone_bis: 'Autre numéro où nous pourrons vous joindre (optionnel)',

        step_hebergement: 'Êtes-vous actuellement hébergé(e) par une personne ou une organisation ?',
        hebergement_organisation: 'Organisation', hebergement_proche: 'Proche/connaissance', hebergement_non: 'Non',
        hosted_by: 'Par qui êtes-vous hébergé(e) ?',
        hosted_by_desc: "Nom de l'organisation qui vous héberge.",

        step_adresse: 'Adresse actuelle',
        adresse: 'Adresse', code_postal: 'Code postal', ville: 'Ville',
        adresse_manual_toggle: 'Je ne trouve pas mon adresse — la saisir manuellement',
        adresse_back_to_search: 'Revenir à la recherche automatique',
        est_hotel_question: "S'agit-il d'un hôtel (hébergement d'urgence) ?",
        se_deplace_question: 'Pouvez-vous vous déplacer pour récupérer votre colis alimentaire si aucun bénévole n\'est disponible pour vous le livrer ?',
        se_deplace_warning: '⚠️ Attention : en fonction du nombre de membres de votre famille, le colis alimentaire peut être assez lourd. Merci de prendre cela en compte avant de répondre.',
        oui: 'Oui', non: 'Non',

        step_situation: 'Situation familiale',
        nombre_adulte: "Combien d'adultes vivent actuellement dans votre foyer ?",
        nombre_enfant: "Combien d'enfants vivent actuellement dans votre foyer ?",
        etudiant_question: 'Êtes-vous étudiant(e) ?',
        circonstances: 'Décrivez brièvement votre situation actuelle',
        circonstances_desc: 'Expliquez en quelques lignes votre situation personnelle et/ou familiale.',

        step_administratif: 'Situation administrative',
        type_piece_identite: "Type de pièce d'identité",
        piece_nationalite: 'Nationalité', piece_titre_sejour: 'Titre de séjour / Récépissé',
        piece_demande_asile: "Demande d'asile", piece_autre: 'Autre',
        documents_identite: "Justificatif d'identité ou de résidence",
        documents_identite_desc: "Merci de joindre un document tel qu'un passeport, une carte d'identité, etc. (5 fichiers max, 10 Mo chacun)",
        documents_choisir_type_dabord: "Merci de sélectionner un type de pièce d'identité ci-dessus pour afficher la zone de dépôt de vos justificatifs.",
        documents_caf: 'Attestation de la CAF (paiement et/ou quotient familial)',
        documents_caf_desc: 'Veuillez fournir votre attestation CAF.',
        documents_ame: "Aide médicale de l'État (AME)",
        documents_ame_desc: 'Veuillez fournir votre attestation AME, si vous en disposez.',

        step_activite: 'Activité professionnelle',
        type_activite: 'Travaillez-vous actuellement, vous ou votre conjoint(e) ?',
        activite_temps_plein: 'Temps plein', activite_temps_partiel: 'Partiellement', activite_non: 'Non',
        work_days: 'Combien de jours par semaine travaillez-vous ?',
        secteur_activite: 'Dans quel secteur travaillez-vous ?',
        secteur_autre: 'Autre secteur',

        step_ressources: 'Ressources',
        organismes_aide: "Percevez-vous actuellement des aides d'autres organismes ?",
        organisme_autre: 'Autre organisme',
        documents_resource: 'Veuillez soumettre tous justificatifs de ressources',
        documents_resource_desc: '(optionnel, 10 fichiers max)',

        success_title: "Merci d'avoir pris le temps de répondre à ce formulaire",
        success_text: "Un email de confirmation vient de vous être envoyé à l'adresse indiquée. Merci de cliquer sur le lien qu'il contient dans les 48 heures pour valider votre demande — sans cette confirmation, votre dossier ne sera pas transmis à notre équipe. Pensez à vérifier vos courriers indésirables si vous ne le recevez pas rapidement.",
        error_generic: 'Une erreur est survenue. Merci de réessayer.',
        error_session_expired: 'Votre session a expiré (formulaire resté ouvert trop longtemps). Merci de recharger la page.',
        error_too_many_attempts: 'Trop de tentatives. Merci de patienter une minute avant de réessayer.',
    },
    en: {
        nav_next: 'Next', nav_prev: 'Previous',
        nav_submit: 'Submit my request', nav_submitting: 'Submitting…',
        step_of: 'Step {n} of {total}',
        champ_requis: 'This field is required.',

        consent_title: 'Personal Data Protection',
        consent_intro_html: 'In accordance with French and European regulations, your personal data is collected and processed in compliance with the <strong>General Data Protection Regulation (GDPR)</strong> and the <strong>French Data Protection Act</strong>.</p><p>The information collected in this form is used solely for the activities of the <strong>AMANA</strong> association, to manage and follow up on your file.</p><p>You can consult all applicable legal provisions here:</p><p>👉 <a href="https://eur-lex.europa.eu/eli/reg/2016/679/oj" target="_blank" rel="noopener noreferrer">Full text of the GDPR on EUR-Lex</a></p><p>For any questions or to exercise your rights (access, correction, deletion, objection, etc.), please contact us:</p><p>📧 <a href="mailto:amana44.pole.social@gmail.com">amana44.pole.social@gmail.com</a><br>📱 <strong>WhatsApp</strong>: +33 7 74 83 24 60',
        consent_accept: 'I accept the terms and conditions regarding the collection and processing of my personal data',
        consent_refuse: 'I refuse the collection and processing of my personal data',
        consent_continue: 'Continue',

        refused_title: 'Refusal',
        refused_text: 'If you refuse to provide the necessary information, we will not be able to process your request.',

        step_identite: 'Personal Information',
        nom: 'Last Name', prenom: 'First Name of the Contact Person',
        email: 'Email',
        telephone: 'Phone Number of the Contact Person',
        telephone_hint: 'Format: 0123456789',
        telephone_invalide: 'Invalid phone number.',
        telephone_bis: 'Another phone number where we can reach you (optional)',

        step_hebergement: 'Are you currently being hosted by a person or an organization?',
        hebergement_organisation: 'Organization', hebergement_proche: 'Friend/acquaintance', hebergement_non: 'No',
        hosted_by: 'Who is hosting you?',
        hosted_by_desc: 'Name of the organization currently hosting you.',

        step_adresse: 'Current Address',
        adresse: 'Address', code_postal: 'Postal Code', ville: 'City',
        adresse_manual_toggle: "I can't find my address — enter it manually",
        adresse_back_to_search: 'Back to automatic search',
        est_hotel_question: 'Is this a hotel (emergency accommodation)?',
        se_deplace_question: 'Are you able to travel to pick up your food package if no volunteer is available to deliver it to you?',
        se_deplace_warning: '⚠️ Please note: depending on the number of people in your household, the food package may be quite heavy. Please keep this in mind when answering.',
        oui: 'Yes', non: 'No',

        step_situation: 'Family Situation',
        nombre_adulte: 'How many adults currently live in your household?',
        nombre_enfant: 'How many children currently live in your household?',
        etudiant_question: 'Are you a student?',
        circonstances: 'Briefly describe your current situation',
        circonstances_desc: 'Explain your personal and/or family situation in a few sentences.',

        step_administratif: 'Administrative Status',
        type_piece_identite: 'Type of Identification Document',
        piece_nationalite: 'Nationality', piece_titre_sejour: 'Residence Permit / Acknowledgement of Application (Récépissé)',
        piece_demande_asile: 'Asylum Application', piece_autre: 'Other',
        documents_identite: 'Proof of Identity or Residence',
        documents_identite_desc: 'Please attach a document such as a passport, national ID card, etc. (5 files max, 10 MB each)',
        documents_choisir_type_dabord: 'Please select a type of identification document above to display the upload area for your supporting documents.',
        documents_caf: 'CAF Certificate (Payment and/or Family Quotient)',
        documents_caf_desc: 'Please provide your CAF certificate.',
        documents_ame: 'State Medical Aid (AME)',
        documents_ame_desc: 'Please provide your AME certificate, if you have one.',

        step_activite: 'Professional Activity',
        type_activite: 'Are you or your spouse currently working?',
        activite_temps_plein: 'Full-time', activite_temps_partiel: 'Part-time', activite_non: 'No',
        work_days: 'How many days per week do you work?',
        secteur_activite: 'Which sector do you work in?',
        secteur_autre: 'Other sector',

        step_ressources: 'Income / Financial Resources',
        organismes_aide: 'Are you currently receiving support from other organizations?',
        organisme_autre: 'Other organization',
        documents_resource: 'Please submit any proof of income or financial support',
        documents_resource_desc: '(optional, 10 files max)',

        success_title: 'Thank you for taking the time to complete this form.',
        success_text: "A confirmation email has just been sent to the address you provided. Please click the link in it within 48 hours to validate your request — without this confirmation, your file will not be sent to our team. Please check your spam folder if you don't receive it promptly.",
        error_generic: 'Something went wrong. Please try again.',
        error_session_expired: 'Your session has expired (the form was left open too long). Please reload the page.',
        error_too_many_attempts: 'Too many attempts. Please wait a minute before trying again.',
    },
    ar: {
        nav_next: 'التالي', nav_prev: 'السابق',
        nav_submit: 'إرسال طلبي', nav_submitting: 'جارٍ الإرسال…',
        step_of: 'الخطوة {n} من {total}',
        champ_requis: 'هذا الحقل إلزامي.',

        consent_title: 'حماية البيانات الشخصية',
        consent_intro_html: 'وفقًا للتشريعات الفرنسية والأوروبية، يتم جمع بياناتكم الشخصية ومعالجتها بما يتوافق مع <strong>اللائحة العامة لحماية البيانات (RGPD)</strong> وقانون <strong>حماية البيانات الفرنسي</strong>.</p><p>تُستخدم المعلومات التي يتم جمعها من خلال هذا النموذج فقط في إطار أنشطة جمعية <strong>أمانة</strong>، وذلك لإدارة ومتابعة ملفكم.</p><p>يمكنكم الاطلاع على جميع الأحكام القانونية ذات الصلة هنا:</p><p>👉 <a href="https://eur-lex.europa.eu/eli/reg/2016/679/oj" target="_blank" rel="noopener noreferrer">النص الكامل للائحة RGPD على موقع EUR-Lex</a></p><p>لأي استفسار أو لممارسة حقوقكم (الوصول إلى البيانات، تصحيحها، حذفها، الاعتراض على معالجتها، وغيرها)، يُرجى التواصل معنا:</p><p>📧 <a href="mailto:amana44.pole.social@gmail.com">amana44.pole.social@gmail.com</a><br>📱 <strong>WhatsApp</strong> : +33 7 74 83 24 60',
        consent_accept: 'أوافق على الشروط والأحكام المتعلقة بجمع ومعالجة بياناتي الشخصية',
        consent_refuse: 'أرفض جمع ومعالجة بياناتي الشخصية',
        consent_continue: 'متابعة',

        refused_title: 'الرفض',
        refused_text: 'في حال الرفض، لن نتمكن من معالجة طلبكم.',

        step_identite: 'المعلومات الشخصية',
        nom: 'اللقب', prenom: 'إسم الشخص الذي يمكن التواصل معه',
        email: 'البريد الإلكتروني',
        telephone: 'رقم هاتف الشخص الذي يمكن التواصل معه',
        telephone_hint: 'الصيغة: 0123456789',
        telephone_invalide: 'رقم هاتف غير صالح.',
        telephone_bis: 'رقم هاتف آخر يمكننا التواصل معك من خلاله (اختياري)',

        step_hebergement: 'هل تتم استضافتك حاليًا من قبل شخص أو منظمة؟',
        hebergement_organisation: 'منظمة', hebergement_proche: 'قريب/معرفة', hebergement_non: 'لا',
        hosted_by: 'من يتكفّل بإقامتك؟',
        hosted_by_desc: 'اسم المنظمة أو الشخص الذي يتكفّل بإقامتك.',

        step_adresse: 'العنوان الحالي',
        adresse: 'العنوان', code_postal: 'الرمز البريدي', ville: 'المدينة',
        adresse_manual_toggle: 'لا أجد عنواني — أدخله يدويًا',
        adresse_back_to_search: 'العودة إلى البحث التلقائي',
        est_hotel_question: 'هل هذا فندق (إقامة طارئة)؟',
        se_deplace_question: 'هل تستطيعون الانتقال لاستلام الطرد الغذائي إذا لم يتوفر أي متطوّع لإيصاله إليكم؟',
        se_deplace_warning: '⚠️ تنبيه: حسب عدد أفراد أسرتكم، قد يكون الطرد الغذائي ثقيلاً. يُرجى أخذ ذلك بعين الاعتبار قبل الإجابة.',
        oui: 'نعم', non: 'لا',

        step_situation: 'الوضع العائلي',
        nombre_adulte: 'كم عدد البالغين الذين يعيشون حاليًا في منزلك؟',
        nombre_enfant: 'كم عدد الأطفال الذين يعيشون حاليًا في منزلك؟',
        etudiant_question: 'هل أنت طالب(ة)؟',
        circonstances: 'صف وضعك الحالي باختصار',
        circonstances_desc: 'يرجى شرح وضعك الشخصي و/أو العائلي في بضع سطور.',

        step_administratif: 'الوضع الإداري',
        type_piece_identite: 'نوع وثيقة الهوية',
        piece_nationalite: 'الجنسية', piece_titre_sejour: 'تصريح الإقامة / إيصال',
        piece_demande_asile: 'طلب لجوء', piece_autre: 'أخرى',
        documents_identite: 'إثبات الهوية أو الإقامة',
        documents_identite_desc: 'يرجى إرفاق وثيقة مثل جواز السفر، بطاقة الهوية، إلخ (5 ملفات كحد أقصى، 10 ميغابايت لكل ملف)',
        documents_choisir_type_dabord: 'يرجى اختيار نوع وثيقة الهوية أعلاه لإظهار منطقة إرفاق المستندات المطلوبة.',
        documents_caf: 'شهادة من CAF (الدفع و/أو الحصّة العائلية)',
        documents_caf_desc: 'يرجى تقديم شهادة CAF الخاصة بك.',
        documents_ame: 'المساعدة الطبية للدولة (AME)',
        documents_ame_desc: 'يرجى تقديم شهادة AME إذا كانت متوفرة لديك.',

        step_activite: 'النشاط المهني',
        type_activite: 'هل تعمل حالياً، أنت أو زوجك/زوجتك؟',
        activite_temps_plein: 'دوام كامل', activite_temps_partiel: 'دوام جزئي', activite_non: 'لا',
        work_days: 'كم يوماً في الأسبوع تعمل؟',
        secteur_activite: 'في أي قطاع تعمل؟',
        secteur_autre: 'قطاع آخر',

        step_ressources: 'الموارد',
        organismes_aide: 'هل تتلقون حالياً مساعدات من منظمات أخرى؟',
        organisme_autre: 'منظمة أخرى',
        documents_resource: 'يرجى تقديم جميع إثباتات الموارد',
        documents_resource_desc: '(اختياري، 10 ملفات كحد أقصى)',

        success_title: 'شكرًا لك على تخصيص الوقت للإجابة على هذا النموذج',
        success_text: 'تم للتو إرسال رسالة تأكيد إلى البريد الإلكتروني الذي قدمته. يرجى الضغط على الرابط الموجود فيها خلال 48 ساعة لتأكيد طلبكم — بدون هذا التأكيد، لن يتم إرسال ملفكم إلى فريقنا. يرجى التحقق من مجلد الرسائل غير المرغوب فيها إذا لم تستلموها بسرعة.',
        error_generic: 'حدث خطأ. يرجى المحاولة مرة أخرى.',
        error_session_expired: 'انتهت صلاحية جلستك (بقي النموذج مفتوحًا لفترة طويلة). يرجى إعادة تحميل الصفحة.',
        error_too_many_attempts: 'محاولات كثيرة جدًا. يرجى الانتظار دقيقة قبل إعادة المحاولة.',
    },
};

const STEP_IDS = ['identite', 'hebergement', 'adresse', 'situation', 'administratif', 'activite', 'ressources'] as const;
type StepId = typeof STEP_IDS[number];

const toast = useToast();

const langue = ref<Langue>('fr');
const t = computed(() => DICT[langue.value]);

function tr(key: string, vars: Record<string, string | number> = {}): string {
    let s = t.value[key] ?? key;
    Object.entries(vars).forEach(([k, v]) => { s = s.replace(`{${k}}`, String(v)); });
    return s;
}

function libelle(option: ListeOption): string {
    if (langue.value === 'ar') return option.libelle_ar;
    if (langue.value === 'en') return option.libelle_en;
    return option.libelle_fr;
}

const storeUrl = ref('');
const refusUrl = ref('');
const secteursActivite = ref<ListeOption[]>([]);
const organismesAide = ref<ListeOption[]>([]);
const googlePlacesKey = ref('');

const phase = ref<Phase>('consent');
const currentStep = ref(0);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const progressPct = computed(() => Math.round(((currentStep.value + 1) / STEP_IDS.length) * 100));
const currentStepId = computed<StepId>(() => STEP_IDS[currentStep.value]);

const form = reactive({
    nom: '', prenom: '', email: '', telephone: '', telephone_bis: '',
    type_hebergement: '' as '' | 'organisation' | 'proche' | 'non', hosted_by: '',
    adresse: '', code_postal: '', ville_texte: '', se_deplace: false, est_hotel: false,
    nombre_adulte: 1, nombre_enfant: 0, etudiant: false, circonstances: '',
    type_piece_identite: '' as '' | 'nationalite' | 'titre_sejour' | 'demande_asile' | 'autre',
    type_activite: '' as '' | 'temps_plein' | 'temps_partiel' | 'non',
    work_days: null as number | null,
    secteurs_activite: [] as number[], secteur_activite_autre: '',
    organismes_aide: [] as number[], organisme_aide_autre: '',
    site_web: '', // piège à robots — voir IntakeController::store, jamais rempli par un humain
});

// Justificatif attendu à l'étape "administratif" — Nationalité/Titre de
// séjour/Demande d'asile → CAF, Autre → AME (même branchement que le
// Google Form historique, voir Famille::type_document_aide côté backend).
const typeDocumentAide = computed<'caf' | 'ame' | null>(() => {
    if (!form.type_piece_identite) return null;
    return form.type_piece_identite === 'autre' ? 'ame' : 'caf';
});

// Palette distincte pour la zone d'upload selon la branche empruntée —
// demande du 09/08/2026 : aide visuellement à confirmer qu'on est bien dans
// la bonne section avant d'y déposer un fichier.
const adminUploadColorClasses = computed(() => {
    if (typeDocumentAide.value === 'ame') {
        return { border: 'border-violet-300', bg: 'bg-violet-50', text: 'text-violet-900', badge: 'bg-violet-600' };
    }
    if (typeDocumentAide.value === 'caf') {
        return { border: 'border-sky-300', bg: 'bg-sky-50', text: 'text-sky-900', badge: 'bg-sky-600' };
    }
    return { border: 'border-ink-faint', bg: 'bg-surface-2', text: 'text-ink', badge: 'bg-ink-faint' };
});

const fichiersIdentite = ref<File[]>([]);
const fichiersAide = ref<File[]>([]);
const fichiersResource = ref<File[]>([]);

function onFiles(e: Event, target: 'identite' | 'aide' | 'resource'): void {
    const files = Array.from((e.target as HTMLInputElement).files ?? []);
    if (target === 'identite') fichiersIdentite.value = files;
    if (target === 'aide') fichiersAide.value = files;
    if (target === 'resource') fichiersResource.value = files;
}

function toggleInArray(arr: number[], id: number): void {
    const i = arr.indexOf(id);
    if (i === -1) arr.push(id); else arr.splice(i, 1);
}

// ── Autocomplétion d'adresse (Google Places, PlaceAutocompleteElement) ──
// google.maps.places.Autocomplete (l'ancien widget) est bloqué pour tout
// projet Google Cloud n'ayant jamais utilisé les Places API avant le
// 1er mars 2025 — donc bloqué pour ce projet. On utilise directement
// PlaceAutocompleteElement (la version actuelle, seule disponible pour un
// nouveau projet), qui nécessite "Places API (New)" activée côté Google
// Cloud Console — voir échange du 09/08/2026 pour le détail.
//
// PlaceAutocompleteElement est un Web Component (pas un simple <input>),
// monté à côté du champ texte plutôt qu'à sa place : la sélection d'une
// suggestion pré-remplit form.adresse/code_postal/ville_texte, mais la
// famille garde la possibilité de saisir une adresse manuellement si elle
// n'apparaît pas dans les suggestions Google (situations d'hébergement
// informel, adresses rurales, etc. — cas réel pour le public d'AMANA).
//
// Dégradation gracieuse totale : si googlePlacesKey est vide (variable
// GOOGLE_MAPS_PLACES_API_KEY non configurée côté .env) ou si le script
// Google échoue à charger (réseau, quota, clé invalide), seul le champ
// texte manuel reste visible — jamais bloquant pour la famille. Clé
// volontairement distincte de celle utilisée par GoogleGeocodingService
// côté serveur (voir config/services.php) : celle-ci est exposée au
// navigateur.
const placeAutocompleteContainerRef = ref<HTMLDivElement | null>(null);
let autocompleteElement: any = null;
// Caché par défaut : la famille voit d'abord la recherche Google, avec un
// bouton pour basculer en saisie manuelle si son adresse n'apparaît pas
// (hébergement informel, adresse rurale, etc.) — demande du 09/08/2026.
// Passe aussi à true automatiquement après une sélection réussie, pour que
// la famille puisse relire/corriger le texte rempli automatiquement.
const manualAdresseMode = ref(false);

function loadGoogleMapsScript(apiKey: string): Promise<void> {
    if (window.__googleMapsLoadPromise) return window.__googleMapsLoadPromise;

    window.__googleMapsLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        // v=weekly : canal recommandé par Google pour importLibrary(), voir
        // https://developers.google.com/maps/documentation/javascript/versions
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&language=${langue.value}`;
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
            requestedLanguage: langue.value,
        });
        // Sans ceci, le widget adopte le thème sombre du navigateur/OS et
        // s'affiche comme une barre noire illisible — c'est le symptôme
        // exact remonté le 09/08/2026. Google expose volontairement ces
        // propriétés CSS malgré le Shadow DOM fermé du composant
        // (voir https://developers.google.com/maps/documentation/javascript/places-ui-kit/custom-styling).
        autocompleteElement.style.width = '100%';
        autocompleteElement.style.setProperty('color-scheme', 'light');
        autocompleteElement.style.setProperty('background-color', '#ffffff');
        autocompleteElement.style.setProperty('border', '1px solid #d6d3d1');
        autocompleteElement.style.setProperty('border-radius', '6px');
        placeAutocompleteContainerRef.value.appendChild(autocompleteElement);

        autocompleteElement.addEventListener('gmp-select', async ({ placePrediction }: any) => {
            const place = placePrediction.toPlace();
            await place.fetchFields({ fields: ['addressComponents', 'formattedAddress'] });

            const numero = extraireComposant(place, 'street_number');
            const rue = extraireComposant(place, 'route');
            form.adresse = [numero, rue].filter(Boolean).join(' ') || place.formattedAddress || form.adresse;

            // Auto-remplissage code postal / ville à la confirmation d'une
            // suggestion — demande du 09/08/2026. 'locality' couvre les
            // grandes villes, 'postal_town' est le repli pour certaines
            // communes.
            const codePostal = extraireComposant(place, 'postal_code');
            if (codePostal) form.code_postal = codePostal;

            const ville = extraireComposant(place, 'locality') || extraireComposant(place, 'postal_town');
            if (ville) form.ville_texte = ville;

            // Révèle le champ texte (pré-rempli) pour relecture/correction —
            // voir déclaration de manualAdresseMode ci-dessus.
            manualAdresseMode.value = true;
        });
    } catch {
        // Le conteneur reste vide ; on bascule directement en saisie
        // manuelle puisque la recherche n'est de toute façon pas
        // disponible — voir commentaire de dégradation gracieuse ci-dessus.
        manualAdresseMode.value = true;
    }
}

// Retour à la recherche Google depuis la saisie manuelle — demande du
// 11/08/2026 : un clic sur "saisie manuelle" ne doit pas être une décision
// irréversible (faux clic possible). Le texte déjà saisi n'est PAS effacé :
// si la famille revient ensuite en saisie manuelle sans rien sélectionner,
// elle retrouve ce qu'elle avait tapé.
//
// autocompleteElement doit être remis à null : son conteneur DOM
// (placeAutocompleteContainerRef) est détruit par Vue quand manualAdresseMode
// passe à true (v-if="googlePlacesKey && !manualAdresseMode" sur la div qui
// le contient) — réutiliser l'ancienne référence ne raccrocherait le widget
// à rien. initAdresseAutocomplete() recrée donc un PlaceAutocompleteElement
// tout neuf dans le conteneur fraîchement remonté par Vue.
async function revenirRechercheAutomatique(): Promise<void> {
    manualAdresseMode.value = false;
    autocompleteElement = null;
    await nextTick();
    await initAdresseAutocomplete();
}

// Le conteneur de la suggestion n'existe dans le DOM que pendant l'étape
// 'adresse' (v-else-if) — on initialise donc le widget au moment où
// l'étape devient visible, pas au montage du composant.
watch(currentStepId, async (id) => {
    if (id === 'adresse') {
        await nextTick();
        initAdresseAutocomplete();
    }
});

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function validateStep(stepId: StepId): boolean {
    errors.value = {};
    const req = (field: string, ok: boolean) => { if (!ok) errors.value[field] = tr('champ_requis'); };
    // Même regex que IntakeController::store (telephone) — vérifiée ici
    // AVANT l'envoi pour que la famille voie l'erreur tout de suite plutôt
    // qu'après avoir rempli tout le reste du formulaire (demande du
    // 09/08/2026, suite à un rejet serveur découvert tardivement).
    const telephoneValide = (v: string) => /^[0-9+\s().-]{6,}$/.test(v.trim());

    switch (stepId) {
        case 'identite':
            req('nom', !!form.nom.trim());
            req('prenom', !!form.prenom.trim());
            if (!form.telephone.trim()) errors.value.telephone = tr('champ_requis');
            else if (!telephoneValide(form.telephone)) errors.value.telephone = t.value.telephone_invalide;
            if (form.telephone_bis.trim() && !telephoneValide(form.telephone_bis)) errors.value.telephone_bis = t.value.telephone_invalide;
            req('email', !!form.email.trim() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()));
            break;
        case 'hebergement':
            req('type_hebergement', !!form.type_hebergement);
            if (form.type_hebergement === 'organisation') req('hosted_by', !!form.hosted_by.trim());
            break;
        case 'adresse':
            req('adresse', !!form.adresse.trim());
            req('code_postal', !!form.code_postal.trim());
            req('ville_texte', !!form.ville_texte.trim());
            break;
        case 'situation':
            req('nombre_adulte', form.nombre_adulte >= 0);
            req('nombre_enfant', form.nombre_enfant >= 0);
            req('circonstances', !!form.circonstances.trim());
            break;
        case 'administratif':
            req('type_piece_identite', !!form.type_piece_identite);
            req('documents_identite', fichiersIdentite.value.length > 0);
            req('documents_aide', fichiersAide.value.length > 0);
            break;
        case 'activite':
            req('type_activite', !!form.type_activite);
            if (form.type_activite === 'temps_partiel') {
                req('work_days', form.work_days !== null && form.work_days >= 0 && form.work_days <= 4);
            }
            if (form.type_activite === 'temps_plein' || form.type_activite === 'temps_partiel') {
                req('secteurs_activite', form.secteurs_activite.length > 0 || !!form.secteur_activite_autre.trim());
            }
            break;
        case 'ressources':
            // Aucun champ obligatoire — "aucune aide perçue" est une réponse
            // valide (voir IntakeController::store, règle 'present').
            break;
    }

    return Object.keys(errors.value).length === 0;
}

function next(): void {
    if (!validateStep(currentStepId.value)) return;
    if (currentStep.value < STEP_IDS.length - 1) {
        currentStep.value++;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        submit();
    }
}

function prev(): void {
    if (currentStep.value > 0) {
        currentStep.value--;
        errors.value = {};
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

async function onConsentAccept(): Promise<void> {
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
        // Le refus doit rester silencieux pour la famille même si la
        // journalisation échoue côté serveur — on ne collecte rien de plus
        // dans tous les cas.
    } finally {
        phase.value = 'refused';
    }
}

async function submit(): Promise<void> {
    if (submitting.value) return; // garde-fou anti double-clic/spam (demande 09/08/2026)
    if (!validateStep('ressources')) return;

    submitting.value = true;
    errors.value = {};

    const data = new FormData();
    const append = (key: string, value: unknown) => {
        if (value === null || value === '') return;
        if (typeof value === 'boolean') data.append(key, value ? '1' : '0');
        else data.append(key, String(value));
    };

    append('nom', form.nom);
    append('prenom', form.prenom);
    append('email', form.email);
    append('telephone', form.telephone);
    append('telephone_bis', form.telephone_bis);
    append('type_hebergement', form.type_hebergement);
    append('hosted_by', form.type_hebergement === 'organisation' ? form.hosted_by : '');
    append('adresse', form.adresse);
    append('code_postal', form.code_postal);
    append('ville_texte', form.ville_texte);
    append('se_deplace', form.se_deplace);
    append('est_hotel', form.est_hotel);
    append('nombre_adulte', form.nombre_adulte);
    append('nombre_enfant', form.nombre_enfant);
    append('etudiant', form.etudiant);
    append('circonstances', form.circonstances);
    append('type_piece_identite', form.type_piece_identite);
    append('type_activite', form.type_activite);
    append('work_days', form.type_activite === 'temps_partiel' ? form.work_days : null);
    append('secteur_activite_autre', form.secteur_activite_autre);
    append('organisme_aide_autre', form.organisme_aide_autre);
    append('langue', langue.value);
    append('consentement', true);
    // Piège à robots : jamais rempli par un humain (voir IntakeController::
    // store). On l'envoie même vide pour que le backend puisse le vérifier.
    data.append('site_web', form.site_web);

    // Champs volontairement omis si vide : le backend traite l'absence de
    // clé comme "aucune sélection" (voir IntakeController::store — les deux
    // sont 'nullable' côté validation, contrairement au reste du formulaire).
    form.secteurs_activite.forEach((id) => data.append('secteurs_activite[]', String(id)));
    form.organismes_aide.forEach((id) => data.append('organismes_aide[]', String(id)));

    fichiersIdentite.value.forEach((f) => data.append('documents_identite[]', f));
    fichiersAide.value.forEach((f) => data.append('documents_aide[]', f));
    fichiersResource.value.forEach((f) => data.append('documents_resource[]', f));

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

        // 419 (jeton CSRF expiré, formulaire resté ouvert trop longtemps) et
        // 429 (throttle:5,1 sur /demande) sont fréquents en usage réel et NE
        // SONT PAS journalisés côté Laravel par défaut (TokenMismatchException
        // et ThrottleRequestsException sont dans $dontReport) — d'où un
        // "rien dans les logs" trompeur. On les distingue ici pour que ce
        // soit diagnosticable sans accès serveur.
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
            console.error('Intake submit failed', res.status, await res.text().catch(() => ''));
            throw new Error(`http_${res.status}`);
        }

        phase.value = 'success';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        // eslint-disable-next-line no-console
        console.error('Intake submit error', e);
        toast.error(t.value.error_generic);
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    const el = document.getElementById('vue-intake-form');
    if (el) {
        const l = el.dataset.langue as Langue;
        if (l && DICT[l]) langue.value = l;
        storeUrl.value = el.dataset.storeUrl ?? '';
        refusUrl.value = el.dataset.refusUrl ?? '';
        googlePlacesKey.value = el.dataset.googlePlacesKey ?? '';
        try {
            secteursActivite.value = JSON.parse(el.dataset.secteursActivite ?? '[]');
            organismesAide.value = JSON.parse(el.dataset.organismesAide ?? '[]');
        } catch {
            secteursActivite.value = [];
            organismesAide.value = [];
        }
    }
});
</script>

<template>
    <!-- Refus du consentement : fin immédiate, aucune donnée collectée -->
    <div v-if="phase === 'refused'" class="bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">
        <div class="text-5xl mb-4">🔒</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ t.refused_title }}</h1>
        <p class="text-ink-muted text-[14px]">{{ t.refused_text }}</p>
    </div>

    <!-- Succès -->
    <div v-else-if="phase === 'success'" class="bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">
        <div class="text-5xl mb-4">✅</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ t.success_title }}</h1>
        <p class="text-ink-muted text-[14px]">{{ t.success_text }}</p>
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
        <!-- Piège à robots : jamais visible/atteignable par un humain (CSS,
             pas display:none — certains bots l'ignorent), jamais rempli.
             Voir IntakeController::store. -->
        <input type="text" v-model="form.site_web" name="site_web" tabindex="-1" autocomplete="off"
            class="absolute -left-[9999px] w-px h-px opacity-0 overflow-hidden" aria-hidden="true">

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{{ tr('step_of', { n: currentStep + 1, total: STEP_IDS.length }) }}</span>
                <span class="text-[11px] text-ink-muted">{{ progressPct }}%</span>
            </div>
            <div class="h-1.5 bg-surface-3 rounded-full overflow-hidden">
                <div class="h-full bg-accent transition-all duration-300" :style="{ width: progressPct + '%' }"></div>
            </div>
        </div>

        <!-- 1. Identité -->
        <section v-if="currentStepId === 'identite'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_identite }}</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.nom }} *</label>
                    <input v-model="form.nom" type="text" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.nom" class="text-[11px] text-rose-600">{{ errors.nom }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.prenom }} *</label>
                    <input v-model="form.prenom" type="text" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.prenom" class="text-[11px] text-rose-600">{{ errors.prenom }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.telephone }} *</label>
                    <input v-model="form.telephone" type="tel" placeholder="0123456789" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <p class="text-[11px] text-ink-faint mt-1">{{ t.telephone_hint }}</p>
                    <span v-if="errors.telephone" class="block text-[11px] text-rose-600">{{ errors.telephone }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.telephone_bis }}</label>
                    <input v-model="form.telephone_bis" type="tel" placeholder="0123456789" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.telephone_bis" class="block text-[11px] text-rose-600">{{ errors.telephone_bis }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.email }} *</label>
                    <input v-model="form.email" type="email" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.email" class="text-[11px] text-rose-600">{{ errors.email }}</span>
                </div>
            </div>
        </section>

        <!-- 2. Hébergement -->
        <section v-else-if="currentStepId === 'hebergement'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_hebergement }}</h2>
            <div class="space-y-2">
                <label v-for="opt in [['organisation','hebergement_organisation'],['proche','hebergement_proche'],['non','hebergement_non']] as const"
                    :key="opt[0]"
                    class="flex items-center gap-2.5 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                    :class="form.type_hebergement === opt[0] ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="radio" name="type_hebergement" :value="opt[0]" v-model="form.type_hebergement" class="w-4 h-4 accent-accent">
                    {{ t[opt[1]] }}
                </label>
                <span v-if="errors.type_hebergement" class="block text-[11px] text-rose-600">{{ errors.type_hebergement }}</span>
            </div>
            <div v-if="form.type_hebergement === 'organisation'" class="mt-3">
                <label class="block text-xs font-semibold text-ink mb-1">{{ t.hosted_by }} *</label>
                <p class="text-[11.5px] text-ink-muted mb-1">{{ t.hosted_by_desc }}</p>
                <input v-model="form.hosted_by" type="text" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                <span v-if="errors.hosted_by" class="text-[11px] text-rose-600">{{ errors.hosted_by }}</span>
            </div>
        </section>

        <!-- 3. Adresse -->
        <section v-else-if="currentStepId === 'adresse'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_adresse }}</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.adresse }} *</label>
                    <!-- Widget Google (PlaceAutocompleteElement) : recherche
                         affichée en premier. Le champ texte manuel reste
                         caché jusqu'à sélection ou clic sur le bouton
                         ci-dessous — voir manualAdresseMode. -->
                    <div v-if="googlePlacesKey && !manualAdresseMode" ref="placeAutocompleteContainerRef" class="mb-2"></div>
                    <button v-if="googlePlacesKey && !manualAdresseMode" type="button" @click="manualAdresseMode = true"
                        class="text-[12px] text-accent underline mb-2 cursor-pointer">
                        {{ t.adresse_manual_toggle }}
                    </button>
                    <input v-if="!googlePlacesKey || manualAdresseMode" v-model="form.adresse" type="text" autocomplete="off"
                        class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <!-- Faux clic sur "saisie manuelle" (ou sur une suggestion) : possibilité
                         de revenir à la recherche Google — voir revenirRechercheAutomatique(). -->
                    <button v-if="googlePlacesKey && manualAdresseMode" type="button" @click="revenirRechercheAutomatique"
                        class="text-[12px] text-accent underline mt-1 cursor-pointer">
                        {{ t.adresse_back_to_search }}
                    </button>
                    <span v-if="errors.adresse" class="text-[11px] text-rose-600">{{ errors.adresse }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.code_postal }} *</label>
                        <input v-model="form.code_postal" type="text" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                        <span v-if="errors.code_postal" class="text-[11px] text-rose-600">{{ errors.code_postal }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.ville }} *</label>
                        <input v-model="form.ville_texte" type="text" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                        <span v-if="errors.ville_texte" class="text-[11px] text-rose-600">{{ errors.ville_texte }}</span>
                    </div>
                </div>
                <label class="flex items-center gap-2.5 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                    :class="form.est_hotel ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="checkbox" v-model="form.est_hotel" class="w-4 h-4 accent-accent">
                    {{ t.est_hotel_question }}
                </label>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.se_deplace_question }} *</label>
                    <p class="text-[11.5px] text-ink-muted mb-2">{{ t.se_deplace_warning }}</p>
                    <div class="flex gap-2">
                        <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-md text-[13.5px] cursor-pointer select-none"
                            :class="form.se_deplace ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                            <input type="radio" :value="true" v-model="form.se_deplace" class="w-4 h-4 accent-accent"> {{ t.oui }}
                        </label>
                        <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-md text-[13.5px] cursor-pointer select-none"
                            :class="!form.se_deplace ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                            <input type="radio" :value="false" v-model="form.se_deplace" class="w-4 h-4 accent-accent"> {{ t.non }}
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Situation familiale -->
        <section v-else-if="currentStepId === 'situation'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_situation }}</h2>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.nombre_adulte }} *</label>
                        <input v-model.number="form.nombre_adulte" type="number" min="0" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.nombre_enfant }} *</label>
                        <input v-model.number="form.nombre_enfant" type="number" min="0" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    </div>
                </div>
                <label class="flex items-center gap-2.5 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                    :class="form.etudiant ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="checkbox" v-model="form.etudiant" class="w-4 h-4 accent-accent">
                    {{ t.etudiant_question }}
                </label>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.circonstances }} *</label>
                    <p class="text-[11.5px] text-ink-muted mb-1">{{ t.circonstances_desc }}</p>
                    <textarea v-model="form.circonstances" rows="4" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent resize-none"></textarea>
                    <span v-if="errors.circonstances" class="text-[11px] text-rose-600">{{ errors.circonstances }}</span>
                </div>
            </div>
        </section>

        <!-- 5. Situation administrative -->
        <section v-else-if="currentStepId === 'administratif'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.step_administratif }}</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-2">{{ t.type_piece_identite }} *</label>
                    <div class="space-y-2">
                        <label v-for="opt in [['nationalite','piece_nationalite'],['titre_sejour','piece_titre_sejour'],['demande_asile','piece_demande_asile'],['autre','piece_autre']] as const"
                            :key="opt[0]"
                            class="flex items-center gap-2.5 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                            :class="form.type_piece_identite === opt[0] ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                            <input type="radio" name="type_piece_identite" :value="opt[0]" v-model="form.type_piece_identite" class="w-4 h-4 accent-accent">
                            {{ t[opt[1]] }}
                        </label>
                    </div>
                    <span v-if="errors.type_piece_identite" class="block text-[11px] text-rose-600">{{ errors.type_piece_identite }}</span>
                </div>

                <!-- Zone d'upload dédiée : n'apparaît qu'une fois le type de
                     pièce d'identité choisi ci-dessus (radio) — avant ça, la
                     famille ne sait pas encore quel justificatif "aide"
                     joindre (CAF vs AME en dépend), donc afficher la zone
                     plus tôt n'a pas de sens et prêtait à confusion (demande
                     du 09/08/2026). La couleur reste alignée sur le type de
                     pièce choisi, pour confirmer visuellement qu'on est dans
                     la bonne branche (CAF vs AME). -->
                <div v-if="form.type_piece_identite" class="rounded-lg border-2 p-4 space-y-4 transition-colors" :class="[adminUploadColorClasses.border, adminUploadColorClasses.bg]">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :class="adminUploadColorClasses.badge"></span>
                        <span class="text-[11px] font-semibold uppercase tracking-wide" :class="adminUploadColorClasses.text">
                            {{ typeDocumentAide === 'ame' ? t.documents_ame : typeDocumentAide === 'caf' ? t.documents_caf : t.documents_identite }}
                        </span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.documents_identite }} *</label>
                        <p class="text-[11.5px] text-ink-muted mb-1">{{ t.documents_identite_desc }}</p>
                        <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="onFiles($event, 'identite')" class="w-full text-[12.5px] text-ink-muted">
                        <span v-if="errors.documents_identite" class="block text-[11px] text-rose-600">{{ errors.documents_identite }}</span>
                    </div>
                    <div v-if="typeDocumentAide">
                        <label class="block text-xs font-semibold text-ink mb-1">{{ typeDocumentAide === 'ame' ? t.documents_ame : t.documents_caf }} *</label>
                        <p class="text-[11.5px] text-ink-muted mb-1">{{ typeDocumentAide === 'ame' ? t.documents_ame_desc : t.documents_caf_desc }}</p>
                        <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="onFiles($event, 'aide')" class="w-full text-[12.5px] text-ink-muted">
                        <span v-if="errors.documents_aide" class="block text-[11px] text-rose-600">{{ errors.documents_aide }}</span>
                    </div>
                </div>
                <p v-else class="text-[12px] text-ink-faint italic">{{ t.documents_choisir_type_dabord }}</p>
            </div>
        </section>

        <!-- 6. Activité professionnelle -->
        <section v-else-if="currentStepId === 'activite'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.type_activite }}</h2>
            <div class="space-y-2">
                <label v-for="opt in [['temps_plein','activite_temps_plein'],['temps_partiel','activite_temps_partiel'],['non','activite_non']] as const"
                    :key="opt[0]"
                    class="flex items-center gap-2.5 px-3 py-2.5 border rounded-md text-[13.5px] text-ink cursor-pointer select-none"
                    :class="form.type_activite === opt[0] ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="radio" name="type_activite" :value="opt[0]" v-model="form.type_activite" class="w-4 h-4 accent-accent">
                    {{ t[opt[1]] }}
                </label>
                <span v-if="errors.type_activite" class="block text-[11px] text-rose-600">{{ errors.type_activite }}</span>
            </div>

            <div v-if="form.type_activite === 'temps_partiel'" class="mt-4">
                <label class="block text-xs font-semibold text-ink mb-1">{{ t.work_days }} *</label>
                <input v-model.number="form.work_days" type="number" min="0" max="4" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                <span v-if="errors.work_days" class="text-[11px] text-rose-600">{{ errors.work_days }}</span>
            </div>

            <div v-if="form.type_activite === 'temps_plein' || form.type_activite === 'temps_partiel'" class="mt-4">
                <label class="block text-xs font-semibold text-ink mb-2">{{ t.secteur_activite }} *</label>
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="secteur in secteursActivite" :key="secteur.id"
                        class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none"
                        :class="form.secteurs_activite.includes(secteur.id) ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                        <input type="checkbox" :checked="form.secteurs_activite.includes(secteur.id)"
                            @change="toggleInArray(form.secteurs_activite, secteur.id)" class="w-4 h-4 accent-accent">
                        {{ libelle(secteur) }}
                    </label>
                </div>
                <input v-model="form.secteur_activite_autre" type="text" :placeholder="t.secteur_autre"
                    class="w-full mt-2 px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 outline-none focus:border-accent">
                <span v-if="errors.secteurs_activite" class="block text-[11px] text-rose-600">{{ errors.secteurs_activite }}</span>
            </div>
        </section>

        <!-- 7. Ressources -->
        <section v-else-if="currentStepId === 'ressources'">
            <h2 class="text-[15px] font-bold text-ink mb-4">{{ t.organismes_aide }}</h2>
            <div class="grid grid-cols-2 gap-2">
                <label v-for="organisme in organismesAide" :key="organisme.id"
                    class="flex items-center gap-2 px-3 py-2 border rounded-md text-[13px] text-ink cursor-pointer select-none"
                    :class="form.organismes_aide.includes(organisme.id) ? 'border-accent bg-accent/5' : 'border-ink-faint'">
                    <input type="checkbox" :checked="form.organismes_aide.includes(organisme.id)"
                        @change="toggleInArray(form.organismes_aide, organisme.id)" class="w-4 h-4 accent-accent">
                    {{ libelle(organisme) }}
                </label>
            </div>
            <input v-model="form.organisme_aide_autre" type="text" :placeholder="t.organisme_autre"
                class="w-full mt-2 px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 outline-none focus:border-accent">

            <div class="mt-4">
                <label class="block text-xs font-semibold text-ink mb-1">{{ t.documents_resource }}</label>
                <p class="text-[11.5px] text-ink-muted mb-1">{{ t.documents_resource_desc }}</p>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                    @change="onFiles($event, 'resource')" class="w-full text-[12.5px] text-ink-muted">
            </div>
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
                {{ currentStep === STEP_IDS.length - 1 ? (submitting ? t.nav_submitting : t.nav_submit) : t.nav_next }}
            </button>
        </div>
    </form>
</template>
