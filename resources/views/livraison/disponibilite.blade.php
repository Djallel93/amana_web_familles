{{-- resources/views/livraison/disponibilite.blade.php --}}
{{--
    Écran authentifié (auth + role:benevole, voir routes/web.php) — pas
    une île Vue complexe : formulaire simple posté en JSON vers
    DisponibiliteController::update() puis rechargé, cohérent avec le
    reste de l'app pour un formulaire de cette taille (véhicule/coverage/
    créneaux). Un vrai composant Vue pourrait remplacer ce squelette si le
    besoin de retour instantané se fait sentir, sans changer le contrat de
    l'endpoint POST.

    Rescopé par journée le 05/09/2026 : UN formulaire par CampagneJournee
    au lieu d'un seul pour toute la campagne — un bénévole peut être
    disponible une journée et pas l'autre (voir
    DisponibiliteController::show()/BenevoleDisponibiliteService). Pour
    une campagne mono-jour (le cas courant, une seule CampagneJournee
    créée automatiquement à la création — voir CampagnesController::store()),
    $journees ne contient qu'un élément et l'écran reste visuellement
    équivalent à avant cette évolution.
--}}
@extends('layouts.app')

@section('title', 'Ma disponibilité — AMANA Familles')

@section('content')
    <div class="max-w-xl mx-auto py-10 space-y-6">
        @php
            $typeLabels = [
                'zakat_el_fitr' => 'Zakat el-fitr',
                'collecte_alimentaire' => 'Collecte alimentaire',
                'don_ponctuel' => 'Don ponctuel',
            ];
        @endphp
        <div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-1">Ma disponibilité</h1>
            <p class="text-ink-muted text-[14px]">
                Campagne du {{ $campagne->date_livraison->format('d/m/Y') }}
                ({{ $typeLabels[$campagne->type] ?? $campagne->type }})
            </p>
        </div>

        @foreach($journees as $journee)
            @php
                $disponibilite = $disponibilites->get($journee->id);
                $creneauxSelectionnes = $disponibilite ? $disponibilite->creneaux->pluck('creneau')->all() : [];
            @endphp
            <form class="form-disponibilite space-y-5 bg-surface border border-surface-border rounded-xl p-6"
                data-id-campagne-journee="{{ $journee->id }}">
                @csrf

                @if($journees->count() > 1)
                    <p class="text-[13px] font-semibold text-ink">
                        {{ $journee->label ?? 'Journée du ' . $journee->date->format('d/m/Y') }}
                        <span class="text-ink-muted font-normal">— {{ $journee->date->format('d/m/Y') }}</span>
                    </p>
                @endif

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
                    <label class="block text-[13px] font-medium text-ink mb-1">Remarques sur ma couverture (optionnel)</label>
                    <textarea name="coverage_notes" rows="2"
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

                <p class="disponibilite-message text-[13px] text-center hidden"></p>
            </form>
        @endforeach
    </div>

    <script>
        document.querySelectorAll('.form-disponibilite').forEach(function (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const message = form.querySelector('.disponibilite-message');
                const donnees = {
                    id_campagne_journee: Number(form.dataset.idCampagneJournee),
                    vehicule_confirme: form.vehicule_confirme.checked,
                    coverage_confirmee: form.coverage_confirmee.checked,
                    coverage_notes: form.coverage_notes.value,
                    creneaux: [...form.querySelectorAll('input[name="creneaux[]"]:checked')].map(el => el.value),
                };

                const reponse = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(donnees),
                });
                const resultat = await reponse.json();

                message.classList.remove('hidden');
                message.textContent = resultat.success ? 'Disponibilité enregistrée.' : "Une erreur s'est produite.";
                message.className = 'disponibilite-message text-[13px] text-center ' + (resultat.success ? 'text-emerald-600' : 'text-rose-600');
            });
        });
    </script>
@endsection
