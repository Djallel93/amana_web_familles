{{-- resources/views/livraison/route-impression.blade.php --}}
{{--
    Feuille de tournée à imprimer pour un chauffeur peu à l'aise avec l'application (octobre 2026) —
    voir MaRouteController::imprimer(). Page HTML autonome, grande police, un bloc par arrêt avec
    adresse complète, téléphones, colis et besoins particuliers. Ni QR ni statut : l'admin valide la
    tournée à la main ; le chauffeur coche simplement « Livré » / « Absent » sur le papier.
--}}
@php
    $benevole = $route->benevole;
    $date = $route->campagneJournee?->date ?? $route->campagne?->date_livraison;
@endphp
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Feuille de tournée #{{ $route->id }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 14px; color: #1c1917; margin: 0; padding: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .barre { margin-bottom: 12px; }
        .barre button { font-size: 14px; padding: 6px 12px; cursor: pointer; }
        .entete { border: 2px solid #1c1917; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }
        .entete p { margin: 2px 0; }
        .totaux { display: flex; gap: 18px; margin-top: 6px; font-weight: bold; }
        .arret { border: 1px solid #78716c; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; page-break-inside: avoid; break-inside: avoid; }
        .arret-tete { display: flex; align-items: baseline; gap: 10px; }
        .ordre { font-size: 22px; font-weight: 800; min-width: 34px; }
        .nom { font-size: 17px; font-weight: bold; }
        .adresse { font-size: 16px; margin: 4px 0; }
        .tel { font-size: 16px; margin: 2px 0; }
        .infos { margin: 4px 0; }
        .badge { display: inline-block; font-size: 12px; padding: 1px 7px; border: 1px solid #a8a29e; border-radius: 999px; margin-right: 4px; }
        .notes { color: #be123c; font-weight: bold; margin: 4px 0; }
        .suivi { margin-top: 8px; display: flex; gap: 22px; align-items: center; font-size: 15px; }
        .ligne { flex: 1; border-bottom: 1px solid #78716c; height: 18px; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>

<body>
    <div class="barre no-print"><button onclick="window.print()">🖨️ Imprimer</button></div>

    <div class="entete">
        <h1>Feuille de tournée #{{ $route->id }}</h1>
        <p>
            @if($date)<strong>{{ $date->translatedFormat('l j F Y') }}</strong> — @endif
            {{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Livraison imposée' }}
        </p>
        <p>
            Chauffeur : <strong>{{ $benevole?->prenom }} {{ $benevole?->nom }}</strong>
            @if($benevole?->telephone) — 📞 {{ \App\Models\Famille::formaterTelephoneFr($benevole->telephone) }}@endif
        </p>
        @if($route->vehiculeType)<p>Véhicule : {{ $route->vehiculeType->type }}</p>@endif
        <div class="totaux">
            <span>{{ $etapes->count() }} arrêt(s)</span>
            <span>📦 {{ $totalColis }} colis</span>
            @if($poidsTotal > 0)<span>{{ $poidsTotal }} kg</span>@endif
        </div>
    </div>

    @forelse($etapes as $etape)
        @php
            $livraison = $etape->livraison;
            $famille = $livraison->famille;
        @endphp
        <div class="arret">
            <div class="arret-tete">
                <span class="ordre">{{ $loop->iteration }}</span>
                <span class="nom">{{ $famille->prenom }} {{ $famille->nom }} <span style="font-weight: normal">— famille #{{ $famille->id }}</span></span>
            </div>
            <p class="adresse">📍 {{ $famille->adresse_complete }}</p>
            @foreach(array_filter([$famille->telephone, $famille->telephone_bis]) as $numero)
                <p class="tel">📞 {{ \App\Models\Famille::formaterTelephoneFr($numero) }}</p>
            @endforeach
            <p class="infos">
                <span class="badge">📦 {{ $livraison->nombre_colis }} colis</span>
                @if($famille->nombre_enfant > 0)<span class="badge">👶 {{ $famille->nombre_enfant }} {{ $famille->nombre_enfant > 1 ? 'enfants' : 'enfant' }}</span>@endif
                @if($famille->etudiant)<span class="badge">🎓 Étudiant</span>@endif
                @if($famille->est_hotel)<span class="badge">🏨 Hôtel</span>@endif
            </p>
            @if($livraison->note_besoins_speciaux)<p class="notes">⚠️ {{ $livraison->note_besoins_speciaux }}</p>@endif
            <div class="suivi">
                <span>☐ Livré</span>
                <span>☐ Absent</span>
                <span class="ligne"></span>
            </div>
        </div>
    @empty
        <p>Aucun arrêt dans cette tournée.</p>
    @endforelse
</body>

</html>
