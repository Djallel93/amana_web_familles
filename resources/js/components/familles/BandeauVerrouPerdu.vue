<!-- resources/js/components/familles/BandeauVerrouPerdu.vue

     Avertissement affiché par DetailPanel.vue quand le battement de cœur du
     verrou (useHeartbeatVerrou.ts) apprend qu'un AUTRE utilisateur a repris
     le dossier pendant qu'il est ouvert ici (19/09/2026, suite du Scénario 5
     du chantier "polling live"). Purement informatif : l'enregistrement reste
     possible (décision du 19/09/2026 — un refus serveur ferait perdre à
     l'éditeur le travail saisi). -->
<script setup lang="ts">
defineProps<{ par: string | null }>();
</script>

<template>
    <div role="alert" data-bandeau-verrou-perdu
        class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[12.5px] text-amber-900">
        <strong>⚠️ {{ par ?? 'Un autre utilisateur' }}</strong> a repris ce dossier pendant que vous l'éditiez.
        Si vous enregistrez, vous écraserez ses modifications.
    </div>
</template>
