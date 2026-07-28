<!-- resources/js/components/intake/IntakeForm.vue -->
<!--
    Formulaire public de demande d'aide (remplace les Google Forms de
    l'ancien système amana_familles — section 8.2 du prompt de migration).

    i18n minimal : un seul dictionnaire de libellés par langue (FR/AR/EN),
    pas de librairie i18n — le champ `langue` de la famille est fixé par la
    page (?langue=ar etc.), pas un sélecteur réactif dans le formulaire.
    La direction RTL est gérée par le <html dir="rtl"> côté Blade, pas ici.
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue';
import { useToast } from '@amana/shared-ui';

type Langue = 'fr' | 'ar' | 'en';

const DICT: Record<Langue, Record<string, string>> = {
    fr: {
        intro: "Ce formulaire nous permet d'étudier votre demande d'aide (Zakat El Fitr, Sadaqa). Toutes les informations sont confidentielles.",
        section_identite: 'Votre identité',
        nom: 'Nom', prenom: 'Prénom', email: 'Email (facultatif)',
        telephone: 'Téléphone', telephone_bis: 'Téléphone secondaire (facultatif)',
        section_foyer: 'Composition du foyer',
        nombre_adulte: "Nombre d'adultes", nombre_enfant: "Nombre d'enfants",
        section_adresse: 'Votre adresse',
        adresse: 'Adresse', code_postal: 'Code postal', ville: 'Ville',
        se_deplace: 'Je peux me déplacer si besoin',
        section_situation: 'Votre situation',
        circonstances: 'Circonstances (facultatif)',
        ressentit: 'Comment vous sentez-vous actuellement ? (facultatif)',
        specificites: 'Particularités à signaler (facultatif)',
        hosted: 'Je suis hébergé(e) chez quelqu\'un',
        hosted_by: 'Par qui ?',
        working: 'Je travaille actuellement',
        work_days: 'Jours travaillés par semaine',
        work_sector: "Secteur d'activité",
        other_aid: 'Je bénéficie déjà d\'une autre aide',
        section_eligibilite: 'Éligibilité',
        zakat_el_fitr: 'Je demande la Zakat El Fitr',
        sadaqa: 'Je demande la Sadaqa',
        section_documents: 'Documents justificatifs',
        documents_identite: "Pièce(s) d'identité (obligatoire)",
        documents_aides_etat: "Justificatif(s) d'aide d'État (facultatif)",
        documents_resource: 'Justificatif(s) de ressources (facultatif)',
        consentement: "J'accepte que mes données personnelles soient traitées par AMANA dans le cadre du traitement de ma demande d'aide, conformément au RGPD.",
        submit: 'Envoyer ma demande',
        submitting: 'Envoi en cours…',
        success_title: 'Demande envoyée !',
        success_text: 'Votre demande a bien été enregistrée. Un membre de notre équipe va l\'étudier prochainement.',
        error_generic: 'Une erreur est survenue. Merci de réessayer.',
    },
    en: {
        intro: 'This form allows us to review your request for aid (Zakat El Fitr, Sadaqa). All information is kept confidential.',
        section_identite: 'Your identity',
        nom: 'Last name', prenom: 'First name', email: 'Email (optional)',
        telephone: 'Phone', telephone_bis: 'Secondary phone (optional)',
        section_foyer: 'Household composition',
        nombre_adulte: 'Number of adults', nombre_enfant: 'Number of children',
        section_adresse: 'Your address',
        adresse: 'Address', code_postal: 'Postal code', ville: 'City',
        se_deplace: 'I can travel if needed',
        section_situation: 'Your situation',
        circonstances: 'Circumstances (optional)',
        ressentit: 'How do you currently feel? (optional)',
        specificites: 'Anything else we should know? (optional)',
        hosted: 'I am currently hosted by someone',
        hosted_by: 'By whom?',
        working: 'I am currently working',
        work_days: 'Working days per week',
        work_sector: 'Sector of activity',
        other_aid: 'I already receive another form of aid',
        section_eligibilite: 'Eligibility',
        zakat_el_fitr: 'I am requesting Zakat El Fitr',
        sadaqa: 'I am requesting Sadaqa',
        section_documents: 'Supporting documents',
        documents_identite: 'ID document(s) (required)',
        documents_aides_etat: 'State aid document(s) (optional)',
        documents_resource: 'Proof of income document(s) (optional)',
        consentement: 'I agree that my personal data will be processed by AMANA to handle my aid request, in accordance with GDPR.',
        submit: 'Submit my request',
        submitting: 'Submitting…',
        success_title: 'Request sent!',
        success_text: 'Your request has been recorded. A team member will review it shortly.',
        error_generic: 'Something went wrong. Please try again.',
    },
    ar: {
        intro: 'يتيح لنا هذا النموذج دراسة طلب المساعدة الخاص بكم (زكاة الفطر، الصدقة). جميع المعلومات سرية.',
        section_identite: 'هويتك',
        nom: 'الاسم العائلي', prenom: 'الاسم الأول', email: 'البريد الإلكتروني (اختياري)',
        telephone: 'الهاتف', telephone_bis: 'هاتف إضافي (اختياري)',
        section_foyer: 'تكوين الأسرة',
        nombre_adulte: 'عدد البالغين', nombre_enfant: 'عدد الأطفال',
        section_adresse: 'عنوانك',
        adresse: 'العنوان', code_postal: 'الرمز البريدي', ville: 'المدينة',
        se_deplace: 'يمكنني التنقل عند الحاجة',
        section_situation: 'وضعيتك',
        circonstances: 'الظروف (اختياري)',
        ressentit: 'كيف تشعر حاليًا؟ (اختياري)',
        specificites: 'أي معلومات أخرى؟ (اختياري)',
        hosted: 'أنا مستضاف لدى شخص آخر',
        hosted_by: 'من قبل من؟',
        working: 'أنا أعمل حاليًا',
        work_days: 'أيام العمل في الأسبوع',
        work_sector: 'قطاع النشاط',
        other_aid: 'أستفيد بالفعل من مساعدة أخرى',
        section_eligibilite: 'الأهلية',
        zakat_el_fitr: 'أطلب زكاة الفطر',
        sadaqa: 'أطلب الصدقة',
        section_documents: 'المستندات الثبوتية',
        documents_identite: 'وثيقة (وثائق) الهوية (إلزامي)',
        documents_aides_etat: 'وثيقة (وثائق) مساعدة الدولة (اختياري)',
        documents_resource: 'وثيقة (وثائق) إثبات الدخل (اختياري)',
        consentement: 'أوافق على معالجة بياناتي الشخصية من قبل AMANA في إطار معالجة طلب المساعدة، وفقًا للائحة العامة لحماية البيانات.',
        submit: 'إرسال طلبي',
        submitting: 'جارٍ الإرسال…',
        success_title: 'تم إرسال الطلب!',
        success_text: 'تم تسجيل طلبك بنجاح. سيقوم أحد أعضاء فريقنا بدراسته قريبًا.',
        error_generic: 'حدث خطأ. يرجى المحاولة مرة أخرى.',
    },
};

const toast = useToast();

const langue = ref<Langue>('fr');
const t = computed(() => DICT[langue.value]);

const storeUrl = ref('');
const submitting = ref(false);
const success = ref(false);
const errors = ref<Record<string, string>>({});

const form = reactive({
    nom: '', prenom: '', email: '', telephone: '', telephone_bis: '',
    nombre_adulte: 1, nombre_enfant: 0,
    adresse: '', code_postal: '', ville_texte: '',
    se_deplace: false,
    circonstances: '', ressentit: '', specificites: '',
    hosted: false, hosted_by: '', working: false, work_days: null as number | null,
    work_sector: '', other_aid: false,
    zakat_el_fitr: false, sadaqa: false,
    consentement: false,
});

const fichiersIdentite = ref<File[]>([]);
const fichiersAidesEtat = ref<File[]>([]);
const fichiersResource = ref<File[]>([]);

function onFiles(e: Event, target: 'identite' | 'aides_etat' | 'resource'): void {
    const files = Array.from((e.target as HTMLInputElement).files ?? []);
    if (target === 'identite') fichiersIdentite.value = files;
    if (target === 'aides_etat') fichiersAidesEtat.value = files;
    if (target === 'resource') fichiersResource.value = files;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const data = new FormData();
    Object.entries(form).forEach(([key, value]) => {
        if (value === null || value === '') return;
        if (typeof value === 'boolean') {
            data.append(key, value ? '1' : '0');
        } else {
            data.append(key, String(value));
        }
    });
    data.append('langue', langue.value);

    fichiersIdentite.value.forEach((f) => data.append('documents_identite[]', f));
    fichiersAidesEtat.value.forEach((f) => data.append('documents_aides_etat[]', f));
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
                Object.entries(body.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]]),
            );
            toast.error(t.value.error_generic);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        if (!res.ok) throw new Error();

        success.value = true;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
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
    }
});
</script>

<template>
    <div v-if="success" class="bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">
        <div class="text-5xl mb-4">✅</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ t.success_title }}</h1>
        <p class="text-ink-muted text-[14px]">{{ t.success_text }}</p>
    </div>

    <form v-else @submit.prevent="submit" class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 space-y-7">

        <p class="text-[13.5px] text-ink-muted leading-relaxed">{{ t.intro }}</p>

        <!-- Identité -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_identite }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.prenom }} *</label>
                    <input v-model="form.prenom" type="text" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.prenom" class="text-[11px] text-rose-600">{{ errors.prenom }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.nom }} *</label>
                    <input v-model="form.nom" type="text" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.nom" class="text-[11px] text-rose-600">{{ errors.nom }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.telephone }} *</label>
                    <input v-model="form.telephone" type="tel" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.telephone" class="text-[11px] text-rose-600">{{ errors.telephone }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.telephone_bis }}</label>
                    <input v-model="form.telephone_bis" type="tel" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.email }}</label>
                    <input v-model="form.email" type="email" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                    <span v-if="errors.email" class="text-[11px] text-rose-600">{{ errors.email }}</span>
                </div>
            </div>
        </section>

        <!-- Foyer -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_foyer }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.nombre_adulte }} *</label>
                    <input v-model.number="form.nombre_adulte" type="number" min="0" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.nombre_enfant }} *</label>
                    <input v-model.number="form.nombre_enfant" type="number" min="0" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                </div>
            </div>
            <span v-if="errors.nombre_adulte" class="block text-[11px] text-rose-600 mt-1">{{ errors.nombre_adulte }}</span>
        </section>

        <!-- Adresse -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_adresse }}</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.adresse }} *</label>
                    <textarea v-model="form.adresse" rows="2" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent resize-none"></textarea>
                    <span v-if="errors.adresse" class="text-[11px] text-rose-600">{{ errors.adresse }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.code_postal }} *</label>
                        <input v-model="form.code_postal" type="text" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                        <span v-if="errors.code_postal" class="text-[11px] text-rose-600">{{ errors.code_postal }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.ville }} *</label>
                        <input v-model="form.ville_texte" type="text" required class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent">
                        <span v-if="errors.ville_texte" class="text-[11px] text-rose-600">{{ errors.ville_texte }}</span>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                    <input v-model="form.se_deplace" type="checkbox" class="w-4 h-4 accent-accent">
                    {{ t.se_deplace }}
                </label>
            </div>
        </section>

        <!-- Situation -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_situation }}</h2>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                        <input v-model="form.hosted" type="checkbox" class="w-4 h-4 accent-accent">
                        {{ t.hosted }}
                    </label>
                    <input v-if="form.hosted" v-model="form.hosted_by" type="text" :placeholder="t.hosted_by"
                        class="px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 outline-none focus:border-accent">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                        <input v-model="form.working" type="checkbox" class="w-4 h-4 accent-accent">
                        {{ t.working }}
                    </label>
                </div>
                <div v-if="form.working" class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.work_days }}</label>
                        <input v-model.number="form.work_days" type="number" min="0" max="7" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 outline-none focus:border-accent">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink mb-1">{{ t.work_sector }}</label>
                        <input v-model="form.work_sector" type="text" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13.5px] bg-surface-2 outline-none focus:border-accent">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                    <input v-model="form.other_aid" type="checkbox" class="w-4 h-4 accent-accent">
                    {{ t.other_aid }}
                </label>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.circonstances }}</label>
                    <textarea v-model="form.circonstances" rows="2" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.ressentit }}</label>
                    <textarea v-model="form.ressentit" rows="2" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.specificites }}</label>
                    <textarea v-model="form.specificites" rows="2" class="w-full px-3 py-2.5 border border-ink-faint rounded-md text-[14px] bg-surface-2 outline-none focus:border-accent resize-none"></textarea>
                </div>
            </div>
        </section>

        <!-- Éligibilité -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_eligibilite }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                    <input v-model="form.zakat_el_fitr" type="checkbox" class="w-4 h-4 accent-accent">
                    {{ t.zakat_el_fitr }}
                </label>
                <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer select-none">
                    <input v-model="form.sadaqa" type="checkbox" class="w-4 h-4 accent-accent">
                    {{ t.sadaqa }}
                </label>
            </div>
        </section>

        <!-- Documents -->
        <section>
            <h2 class="text-[12px] font-bold uppercase tracking-wide text-accent-dark mb-3">{{ t.section_documents }}</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.documents_identite }}</label>
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" required
                        @change="onFiles($event, 'identite')"
                        class="w-full text-[12.5px] text-ink-muted">
                    <span v-if="errors.documents_identite" class="block text-[11px] text-rose-600">{{ errors.documents_identite }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.documents_aides_etat }}</label>
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png"
                        @change="onFiles($event, 'aides_etat')"
                        class="w-full text-[12.5px] text-ink-muted">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-ink mb-1">{{ t.documents_resource }}</label>
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png"
                        @change="onFiles($event, 'resource')"
                        class="w-full text-[12.5px] text-ink-muted">
                </div>
            </div>
        </section>

        <!-- Consentement -->
        <section class="pt-4 border-t border-surface-3">
            <label class="flex items-start gap-2.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
                <input v-model="form.consentement" type="checkbox" required class="w-4 h-4 accent-accent mt-0.5 flex-shrink-0">
                <span>{{ t.consentement }}</span>
            </label>
            <span v-if="errors.consentement" class="block text-[11px] text-rose-600 mt-1">{{ errors.consentement }}</span>
        </section>

        <button type="submit" :disabled="submitting"
            class="w-full min-h-[50px] px-6 py-3.5 bg-accent hover:bg-accent-dark disabled:opacity-50 text-white font-bold text-[14px] rounded-lg
                    shadow-[0_3px_14px_rgba(180,83,9,0.3)] transition-all cursor-pointer">
            {{ submitting ? t.submitting : t.submit }}
        </button>
    </form>
</template>
