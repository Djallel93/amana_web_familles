{{-- resources/views/emails/partials/_features-card.blade.php --}}
{{--
Variable:
$featuresLabel — e.g. 'Une fois connecté, vous pourrez'
--}}
<div class="features-card">
    <div class="features-label">&#10022; &nbsp; {{ $featuresLabel }}</div>
    <div class="feature-row">
        <div class="feature-icon">🏠</div>
        <div class="feature-text"><strong>Consulter les dossiers familles</strong> (Zakat El Fitr, Sadaqa)</div>
    </div>
    <div class="feature-row">
        <div class="feature-icon">📍</div>
        <div class="feature-text"><strong>Filtrer par quartier, secteur ou ville</strong></div>
    </div>
    <div class="feature-row">
        <div class="feature-icon">📄</div>
        <div class="feature-text"><strong>Consulter les documents justificatifs</strong> associés à chaque dossier</div>
    </div>
    <div class="feature-row">
        <div class="feature-icon">📊</div>
        <div class="feature-text"><strong>Suivre les statistiques</strong> de traitement des dossiers</div>
    </div>
</div>
