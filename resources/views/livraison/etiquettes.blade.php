{{-- resources/views/livraison/etiquettes.blade.php --}}
{{--
    Étiquettes de colis — une par personne du foyer (voir le prompt du
    30/08/2026 §2 : "each person gets one package"). Recto : famille +
    "colis X/N". Verso : QR de secours (voir PackagingController::etiquettes()
    pour le cas où la tournée n'existe pas encore).
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Étiquettes — {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; }
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
    <button class="no-print" onclick="window.print()">🖨️ Imprimer</button>

    @for($i = 1; $i <= $livraison->nombre_personnes; $i++)
        <div class="etiquette">
            <h2>{{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}</h2>
            <p class="part">Colis {{ $i }} / {{ $livraison->nombre_personnes }}</p>

            <div class="verso">
                @if($qrSvg)
                    {!! $qrSvg !!}
                    <p class="absent">Scan de secours (confirmation livraison)</p>
                @else
                    <p class="absent">QR indisponible — tournée pas encore générée, réimprimer après.</p>
                @endif
            </div>
        </div>
    @endfor
</body>

</html>
