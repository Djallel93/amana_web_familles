{{-- resources/views/livraison/retrait-hq-impression.blade.php --}}
{{--
    Liste imprimable du Retrait QG (23/10/2026) — voir RetraitHqController::imprimer().
    Page HTML autonome (pas @extends('layouts.app')), comme feuille-preparation.blade.php :
    destinée à l'impression navigateur. Contrairement à la feuille de préparation (équipe
    packaging, sans identité), cette liste est tenue par l'équipe qui remet les colis au QG :
    nom et téléphones sont nécessaires pour reconnaître/joindre la famille.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Retrait QG — liste des familles — {{ $campagne->date_livraison->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: sans-serif; font-size: 13px; color: #1c1917; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d6d3d1; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #f5f5f4; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        .centre { text-align: center; }
        .case { font-size: 22px; line-height: 1; }
        .tel { white-space: nowrap; display: block; }
        @media print {
            .no-print { display: none; }
            body { font-size: 11px; }
        }
    </style>
</head>

<body>
    <button class="no-print" onclick="window.print()">🖨️ Imprimer</button>
    <h1>Retrait QG — liste des familles — {{ $campagne->date_livraison->format('d/m/Y') }}</h1>
    <p>{{ $livraisons->count() }} famille(s)</p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nom complet</th>
                <th>Téléphone(s)</th>
                <th>Créneau de réception</th>
                <th class="centre">Nb de colis</th>
                <th class="centre">Livré</th>
            </tr>
        </thead>
        <tbody>
            @foreach($livraisons as $livraison)
                <tr>
                    <td>#{{ $livraison->famille->id }}</td>
                    <td>{{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}</td>
                    <td>
                        @forelse(array_filter([$livraison->famille->telephone, $livraison->famille->telephone_bis]) as $numero)
                            <span class="tel">{{ \App\Models\Famille::formaterTelephoneFr($numero) }}</span>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td>{{ $livraison->heure_arrivee_prevue_hq?->translatedFormat('H:i') ?? 'Non planifié' }}</td>
                    <td class="centre">{{ $livraison->nombre_colis }}</td>
                    {{-- Case à cocher à la main ; déjà cochée si la famille est marquée livrée dans l'app. --}}
                    <td class="centre"><span class="case">{{ $livraison->statut_retrait_hq === 'delivre' ? '☑' : '☐' }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($livraisons->isEmpty())
        <p>Aucune famille se_deplace confirmée pour cette campagne.</p>
    @endif
</body>

</html>
