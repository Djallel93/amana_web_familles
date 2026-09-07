{{-- resources/views/livraison/feuille-preparation.blade.php --}}
{{--
    Feuille de préparation — imprimable/réimprimable à tout moment, sans
    lien avec l'état route/bénévole (voir le prompt du 30/08/2026 §3.4).
    Page HTML autonome (pas @extends('layouts.app')) : destinée à
    l'impression navigateur, pas à la navigation dans l'app.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Feuille de préparation — {{ $campagne->date_livraison->format('d/m/Y') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #1c1917; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d6d3d1; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f5f5f4; }
        .badge { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 999px; background: #e7e5e4; margin-right: 3px; }
        .notes { color: #be123c; }
        @media print {
            .no-print { display: none; }
            body { font-size: 11px; }
        }
    </style>
</head>

<body>
    <button class="no-print" onclick="window.print()">🖨️ Imprimer</button>
    <h1>Feuille de préparation — {{ $campagne->date_livraison->format('d/m/Y') }}</h1>
    <p>{{ $livraisons->count() }} famille(s)</p>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>ID famille</th>
                <th>Adultes</th>
                <th>Enfants</th>
                <th>Particularités</th>
                <th>Besoins spéciaux</th>
                <th>Conditionné</th>
            </tr>
        </thead>
        <tbody>
            @foreach($livraisons as $index => $livraison)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    {{-- Pas de nom/téléphone ici (05/09/2026, prompt §4.4) —
                         cette feuille reste entre les mains de l'équipe
                         packaging, qui n'a besoin que de préparer le bon
                         nombre de colis, pas de l'identité de la famille. --}}
                    <td>#{{ $livraison->famille->id }}</td>
                    <td>{{ $livraison->famille->nombre_adulte }}</td>
                    <td>{{ $livraison->famille->nombre_enfant }}</td>
                    <td>
                        @if($livraison->famille->etudiant)<span class="badge">Étudiant</span>@endif
                        @if($livraison->famille->est_hotel)<span class="badge">Hôtel</span>@endif
                    </td>
                    <td class="notes">{{ $livraison->note_besoins_speciaux }}</td>
                    <td>{{ $livraison->statut_conditionnement === 'prete' ? '✓' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
