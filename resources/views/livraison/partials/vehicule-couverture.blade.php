{{--
    Blocs « Véhicule » et « Couverture » d'UNE journée — partagés entre la
    page de disponibilité du bénévole (livraison/disponibilite.blade.php) et
    la section « Par campagne / journée » de la fiche personne admin
    (personnes/form.blade.php), pour que les deux écrans proposent
    exactement la même saisie (01/10/2026).

    Variables : $vehicules (liste des véhicules avec permis, sans « Sans
    permis »), $villes (villes → secteurs), $profil (BenevoleProfil, pour
    afficher les valeurs « de mon profil »), $etat (état initial, voir
    BenevoleDisponibiliteService::etatFormulaire() — omis sur la fiche
    admin, où le JS applique l'état de la journée choisie).

    Comportement (resources/views/livraison/partials/
    vehicule-couverture-script.blade.php) : cocher « même que mon profil »
    grise la liste correspondante ; la liste de véhicules n'est accessible
    qu'avec le permis coché ; cocher une ville coche tous ses secteurs.
--}}
@php
    $etat = $etat ?? [];
    $nbSecteursProfil = $profil ? $profil->secteurs->count() : 0;
@endphp
<div class="space-y-5" data-vc-root data-etat='@json($etat)'>

    <section class="space-y-2">
        <h3 class="text-[13px] font-semibold text-ink">Véhicule</h3>

        <label class="flex items-start gap-2 text-[14px] text-ink">
            <input type="checkbox" data-vc="vehicule-confirme" class="mt-1">
            <span>
                Mon véhicule correspond toujours à mon profil
                @if($profil?->vehiculeType)
                    <strong class="font-semibold">({{ $profil->vehiculeType->type }})</strong>
                @endif
            </span>
        </label>

        <fieldset data-vc="vehicule-champs" class="space-y-2 transition-opacity">
            <label class="flex items-center gap-2 text-[14px] text-ink">
                <input type="checkbox" data-vc="permis">
                Titulaire du permis de conduire
            </label>
            <select data-vc="vehicule-type"
                class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2 text-[14px] text-ink disabled:opacity-50 disabled:cursor-not-allowed">
                <option value="">Choisir un véhicule…</option>
                @foreach($vehicules as $vehicule)
                    <option value="{{ $vehicule['id'] }}">{{ $vehicule['type'] }}</option>
                @endforeach
            </select>
            <p data-vc="note-sans-permis" hidden class="text-[12px] text-ink-muted">
                Sans permis de conduire, aucun véhicule n'est à choisir : vous ne serez pas proposé pour une tournée.
            </p>
        </fieldset>

        <p data-vc="erreur-vehicule" hidden class="text-[12.5px] text-rose-600"></p>
    </section>

    <section class="space-y-2">
        <h3 class="text-[13px] font-semibold text-ink">Couverture</h3>

        <label class="flex items-start gap-2 text-[14px] text-ink">
            <input type="checkbox" data-vc="coverage-confirmee" class="mt-1">
            <span>
                Je confirme ma zone de couverture habituelle
                @if($nbSecteursProfil > 0)
                    <strong class="font-semibold">({{ $nbSecteursProfil }} secteur{{ $nbSecteursProfil > 1 ? 's' : '' }})</strong>
                @endif
            </span>
        </label>

        <fieldset data-vc="couverture-champs" class="space-y-2 transition-opacity">
            <div class="flex items-center justify-between">
                <span class="text-[12px] text-ink-muted">Villes et secteurs couverts</span>
                <button type="button" data-vc="tout-rien"
                    class="text-[11.5px] font-medium px-2.5 py-1 rounded-lg border border-accent text-accent hover:bg-accent/5 disabled:opacity-50 disabled:cursor-not-allowed">
                    Tout / Rien
                </button>
            </div>

            <div class="border border-surface-border rounded-lg bg-surface p-2 space-y-1 max-h-72 overflow-y-auto">
                @forelse($villes as $ville)
                    <details class="rounded-md hover:bg-surface-2">
                        <summary class="flex items-center gap-2 px-1.5 py-1 cursor-pointer text-[13.5px] text-ink select-none">
                            <input type="checkbox" data-vc="ville" data-ville="{{ $ville['id'] }}">
                            <span class="font-medium">{{ $ville['nom'] }}</span>
                            <span data-vc="compteur-ville" data-ville="{{ $ville['id'] }}" class="ml-auto text-[11px] text-ink-muted"></span>
                        </summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 pl-7 pr-2 pb-2 pt-1">
                            @foreach($ville['secteurs'] as $secteur)
                                <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                                    <input type="checkbox" data-vc="secteur" data-ville="{{ $ville['id'] }}" value="{{ $secteur['id'] }}">
                                    {{ $secteur['nom'] }}
                                </label>
                            @endforeach
                        </div>
                    </details>
                @empty
                    <p class="text-[12.5px] text-ink-muted px-1.5 py-1">Aucun secteur disponible.</p>
                @endforelse
            </div>
        </fieldset>

        <p data-vc="erreur-couverture" hidden class="text-[12.5px] text-rose-600"></p>
    </section>
</div>
