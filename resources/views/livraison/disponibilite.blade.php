{{-- resources/views/livraison/disponibilite.blade.php --}}
{{--
    Écran authentifié (auth + role:benevole, voir routes/web.php) — pas
    une île Vue complexe : formulaire simple posté en JSON vers
    DisponibiliteController::update() puis rechargé, cohérent avec le
    reste de l'app pour un formulaire de cette taille (véhicule/coverage/
    créneaux). Un vrai composant Vue pourrait remplacer ce squelette si le
    besoin de retour instantané se fait sentir, sans changer le contrat de
    l'endpoint POST.
--}}
@extends('layouts.app')

@section('title', 'Ma disponibilité — AMANA Familles')

@section('content')
    <div class="max-w-xl mx-auto py-10">
        @php
            $typeLabels = [
                'zakat_el_fitr' => 'Zakat el-fitr',
                'collecte_alimentaire' => 'Collecte alimentaire',
                'don_ponctuel' => 'Don ponctuel',
            ];
        @endphp
        <h1 class="font-heading text-xl font-semibold text-ink mb-1">Ma disponibilité</h1>
        <p class="text-ink-muted text-[14px] mb-6">
            Campagne du {{ $campagne->date_livraison->format('d/m/Y') }}
            ({{ $typeLabels[$campagne->type] ?? $campagne->type }})
        </p>

        <form id="form-disponibilite" class="space-y-5 bg-surface border border-surface-border rounded-xl p-6">
            @csrf

            <label class="flex items-center gap-2 text-[14px] text-ink">
                <input type="checkbox" name="vehicule_confirme" value="1"
                    @checked($disponibilite?->vehicule_confirme)>
                Mon véhicule correspond toujours à mon profil bénévole
                @if($profil?->vehiculeType)
                    ({{ $profil->vehiculeType->type }})
                @endif
            </label>

            <label class="flex items-center gap-2 text-[14px] text-ink">
                <input type="checkbox" name="coverage_confirmee" value="1"
                    @checked($disponibilite?->coverage_confirmee)>
                Je confirme ma zone de couverture habituelle
            </label>

            <div>
                <label for="coverage_notes" class="block text-[13px] font-medium text-ink mb-1">Remarques sur ma couverture (optionnel)</label>
                <textarea name="coverage_notes" id="coverage_notes" rows="2"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]">{{ $disponibilite?->coverage_notes }}</textarea>
            </div>

            <div>
                <span class="block text-[13px] font-medium text-ink mb-1">Créneaux disponibles</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach($creneaux as $valeur => $libelle)
                        <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                            <input type="checkbox" name="creneaux[]" value="{{ $valeur }}"
                                @checked(in_array($valeur, $creneauxSelectionnes))>
                            {{ $libelle }}
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-[14px] font-medium py-2.5 hover:opacity-90 transition-opacity">
                {{ $disponibilite ? 'Mettre à jour' : 'Confirmer' }}
            </button>

            <p id="disponibilite-message" class="text-[13px] text-center hidden"></p>
        </form>
    </div>

    <script>
        document.getElementById('form-disponibilite').addEventListener('submit', async function (e) {
            e.preventDefault();
            const form = e.target;
            const message = document.getElementById('disponibilite-message');
            const donnees = {
                vehicule_confirme: form.vehicule_confirme.checked,
                coverage_confirmee: form.coverage_confirmee.checked,
                coverage_notes: form.coverage_notes.value,
                creneaux: [...form.querySelectorAll('input[name="creneaux[]"]:checked')].map(el => el.value),
            };

            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(donnees),
            });
            const resultat = await reponse.json();

            message.classList.remove('hidden');
            message.textContent = resultat.success ? 'Disponibilité enregistrée.' : "Une erreur s'est produite.";
            message.className = resultat.success ? 'text-[13px] text-center text-emerald-600' : 'text-[13px] text-center text-rose-600';
        });
    </script>
@endsection
