{{-- resources/views/livraison/etiquettes-campagne.blade.php --}}
{{--
    Planche d'étiquettes QR pour toutes les familles confirmées de la
    campagne (07/09/2026, prompt §4.1) — remplace l'ancienne
    etiquettes.blade.php par famille (retirée par ce même patch, voir
    PackagingController §3.1) : une seule page à imprimer et découper au
    lieu d'une impression par famille. Reprend le même style de vignette
    à bordure pointillée ("page-break-inside: avoid" pour ne jamais
    couper une étiquette entre deux pages), voir ChargementController::
    etiquettesCampagne() pour le détail du QR de secours.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Étiquettes — {{ $campagne->type }} {{ $campagne->date_livraison }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; }
        .planche { display: flex; flex-wrap: wrap; gap: 24px; }
        .etiquette { width: 300px; border: 1px dashed #a8a29e; border-radius: 8px; padding: 16px; margin-bottom: 24px; page-break-inside: avoid; }
        .etiquette h2 { margin: 0 0 4px; font-size: 16px; }
        .etiquette .part { font-size: 28px; font-weight: bold; margin: 8px 0; }
        .verso { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #a8a29e; text-align: center; }
        .verso .absent { font-size: 12px; color: #78716c; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>

<body>
    <button class="no-print" onclick="window.print()">🖨️ Imprimer toute la planche</button>

    <div class="planche">
        @foreach($livraisons as $livraison)
            @for($i = 1; $i <= $livraison->nombre_personnes; $i++)
                <div class="etiquette">
                    <h2>{{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}</h2>
                    <p class="part">Colis {{ $i }} / {{ $livraison->nombre_personnes }}</p>

                    <div class="verso">
                        @if($qrParLivraison[$livraison->id])
                            {!! $qrParLivraison[$livraison->id] !!}
                            <p class="absent">Scan de secours (confirmation livraison)</p>
                        @else
                            <p class="absent">QR indisponible — tournée pas encore générée, réimprimer après.</p>
                        @endif
                    </div>
                </div>
            @endfor
        @endforeach
    </div>

    @if($livraisons->isEmpty())
        <p>Aucune famille confirmée pour cette campagne.</p>
    @endif
</body>

</html>
