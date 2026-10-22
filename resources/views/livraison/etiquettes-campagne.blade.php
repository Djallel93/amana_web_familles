{{-- resources/views/livraison/etiquettes-campagne.blade.php --}}
{{--
    Planche d'étiquettes de la campagne (refonte d'octobre 2026) — A4, 3 colonnes × 4 lignes.
    Pour chaque planche de 12 étiquettes, DEUX pages se suivent :
      - recto : uniquement le numéro de famille (grand, gras, centré), « n/total » en bas à droite ;
      - verso : le QR code, colonnes inversées par ligne pour tomber derrière son étiquette en
        impression recto-verso (retournement sur le bord long).
    Aucun nom (famille ou chauffeur) n'est imprimé. Voir ChargementController::etiquettesCampagne().
    À imprimer à 100 % (pas « ajuster à la page »).
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Étiquettes — {{ $campagne->type }} {{ $campagne->date_livraison }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: sans-serif; color: #1c1917; }
        .barre { padding: 12px 16px; font-size: 14px; background: #f5f5f4; border-bottom: 1px solid #d6d3d1; }
        .barre button { font-size: 14px; padding: 6px 12px; margin-right: 12px; cursor: pointer; }
        .page { width: 210mm; height: 297mm; display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(4, 1fr); page-break-after: always; break-after: page; overflow: hidden; }
        .page:last-of-type { page-break-after: auto; break-after: auto; }
        .case { position: relative; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .recto .case { border: 0.2mm dashed #a8a29e; }
        .famille-id { font-size: 22mm; font-weight: 800; line-height: 1; text-align: center; }
        .numero { position: absolute; right: 4mm; bottom: 3mm; font-size: 5mm; font-weight: 600; }
        .case svg { width: 48mm; height: 48mm; }
        @media screen { .page { margin: 12px auto; box-shadow: 0 0 0 1px #d6d3d1; background: #fff; } }
        @media print { .no-print { display: none; } .page { margin: 0; } }
    </style>
</head>

<body>
    <div class="barre no-print">
        <button onclick="window.print()">🖨️ Imprimer</button>
        {{ $nombreEtiquettes }} étiquette(s) — {{ count($pages) }} planche(s), chacune sur deux pages (recto puis verso).
        Imprimer en recto-verso, retournement sur le bord long, échelle 100 %.
    </div>

    @foreach($pages as $page)
        <section class="page recto">
            @foreach($page['recto'] as $etiquette)
                <div class="case">
                    @if($etiquette)
                        <span class="famille-id">{{ $etiquette['famille_id'] }}</span>
                        <span class="numero">{{ $etiquette['numero'] }}/{{ $etiquette['total'] }}</span>
                    @endif
                </div>
            @endforeach
        </section>
        <section class="page verso">
            @foreach($page['verso'] as $etiquette)
                <div class="case">
                    @if($etiquette)
                        {!! $etiquette['qr'] !!}
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach

    @if($nombreEtiquettes === 0)
        <p style="padding: 16px">Aucune famille confirmée pour cette campagne.</p>
    @endif
</body>

</html>
