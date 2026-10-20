<!-- resources/js/pages/Guide/GuideIndex.vue -->
<!--
    Page Inertia « Guide d'utilisation » (18/10/2026), servie par GuideController::index() — ouverte par le bouton ❓
    de la sidebar partagée (route guide.index).

    Squelette partagé : en-tête, sommaire collant, sections et accordéons viennent de @amana/shared-ui (GuidePage,
    GuideSection, GuideItem), comme le guide des autres apps AMANA. Le CONTENU, lui, vient du serveur
    (App\Support\Guide\GuideContenu) déjà filtré par rôle : cette page ne décide rien, elle rend ce qu'elle reçoit.
    Les contenus HTML sont écrits en dur côté serveur (jamais une saisie utilisateur), d'où le v-html.
-->
<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { GuideItem, GuidePage, GuideSection } from "@amana/shared-ui";

interface GuideItemData {
    icon: string;
    title: string;
    open: boolean;
    html: string;
    example: string | null;
}

interface GuideSectionData {
    id: string;
    icon: string;
    title: string;
    subtitle: string | null;
    items: GuideItemData[];
}

const props = defineProps<{
    sections: GuideSectionData[];
}>();

const sommaire = computed(() => props.sections.map((s) => ({ id: s.id, icon: s.icon, title: s.title })));
</script>

<template>
    <Head title="Guide d'utilisation — AMANA Familles" />

    <GuidePage
        title="Guide d'utilisation"
        subtitle="Tout ce qu'il faut savoir pour utiliser AMANA Familles selon votre rôle — cliquez sur une question pour la déplier"
        :sections="sommaire"
    >
        <GuideSection
            v-for="(section, index) in sections"
            :id="section.id"
            :key="section.id"
            :icon="section.icon"
            :title="section.title"
            :subtitle="section.subtitle ?? undefined"
            :delay="60 * (index + 1)"
        >
            <GuideItem
                v-for="item in section.items"
                :key="item.title"
                :icon="item.icon"
                :title="item.title"
                :open="item.open"
            >
                <div v-html="item.html" />
                <template v-if="item.example" #example>{{ item.example }}</template>
            </GuideItem>
        </GuideSection>
    </GuidePage>
</template>
