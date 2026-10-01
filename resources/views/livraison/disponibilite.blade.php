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

    Refonte du 01/10/2026 : deux sections « Véhicule » et « Couverture »
    (livraison/partials/vehicule-couverture.blade.php, partagée avec la
    fiche personne admin) à la place des deux cases isolées + du champ
    « remarques sur ma couverture » (supprimé) ; créneaux regroupés Matin /
    Après-midi comme sur Suivi des bénévoles.
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

                @include('livraison.partials.vehicule-couverture', [
                    'etat' => $etats[$journee->id] ?? [],
                    'vehicules' => $vehicules,
                    'villes' => $villes,
                    'profil' => $profil,
                ])

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[13px] font-medium text-ink">Créneaux disponibles</span>
                        <!--
                            Ajouté le 08/09/2026 (prompt de cette date §5.3)
                            : "add a toggle for all/nothing (like in
                            famille)" — même comportement que toggleTout()
                            dans ContactsQueue.vue (Suivi des contacts),
                            réécrit ici en JS vanilla puisque cet écran
                            n'est pas une île Vue (voir commentaire en tête
                            de fichier).
                        -->
                        <button type="button" class="toggle-tout-rien text-[11.5px] font-medium px-2.5 py-1 rounded-lg border border-accent text-accent hover:bg-accent/5">
                            Tout / Rien
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach(['matin' => ['Matin', $creneauxMatin], 'apres-midi' => ['Après-midi', $creneauxApresMidi]] as $cleGroupe => [$titreGroupe, $membres])
                            <div class="border border-surface-border rounded-lg p-2">
                                <label class="flex items-center gap-1.5 text-[12.5px] font-medium text-ink mb-1.5">
                                    <input type="checkbox" class="groupe-creneaux" data-groupe="{{ $cleGroupe }}">
                                    {{ $titreGroupe }}
                                </label>
                                <div class="space-y-1.5">
                                    @foreach($membres as $valeur)
                                        <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                                            <input type="checkbox" name="creneaux[]" value="{{ $valeur }}"
                                                data-groupe-membre="{{ $cleGroupe }}"
                                                @checked(in_array($valeur, $creneauxSelectionnes))>
                                            {{ $creneaux[$valeur] }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
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

    @include('livraison.partials.vehicule-couverture-script')

    <script>
        document.querySelectorAll('.form-disponibilite').forEach(function (form) {
            const racine = form.querySelector('[data-vc-root]');
            const cases = () => [...form.querySelectorAll('input[name="creneaux[]"]')];

            // Cases de groupe Matin / Après-midi (01/10/2026) : cochées si
            // tous leurs créneaux le sont, « indéterminées » si une partie.
            function rafraichirGroupes() {
                form.querySelectorAll('.groupe-creneaux').forEach(function (groupe) {
                    const membres = cases().filter((c) => c.dataset.groupeMembre === groupe.dataset.groupe);
                    const n = membres.filter((c) => c.checked).length;
                    groupe.checked = n > 0 && n === membres.length;
                    groupe.indeterminate = n > 0 && n < membres.length;
                });
            }

            form.querySelectorAll('.groupe-creneaux').forEach(function (groupe) {
                groupe.addEventListener('change', function () {
                    cases().filter((c) => c.dataset.groupeMembre === groupe.dataset.groupe)
                        .forEach((c) => { c.checked = groupe.checked; });
                    rafraichirGroupes();
                });
            });
            cases().forEach((c) => c.addEventListener('change', rafraichirGroupes));
            rafraichirGroupes();

            // Tout / Rien (08/09/2026, prompt de cette date §5.3) — coche
            // tout si au moins une case est décochée, sinon décoche tout,
            // même règle que toggleTout() côté admin (ContactsQueue.vue).
            const toggleToutRien = form.querySelector('.toggle-tout-rien');
            if (toggleToutRien) {
                toggleToutRien.addEventListener('click', function () {
                    const toutCoche = cases().every((c) => c.checked);
                    cases().forEach((c) => { c.checked = !toutCoche; });
                    rafraichirGroupes();
                });
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const message = form.querySelector('.disponibilite-message');
                const donnees = Object.assign({
                    id_campagne_journee: Number(form.dataset.idCampagneJournee),
                    creneaux: cases().filter((c) => c.checked).map((c) => c.value),
                }, window.VehiculeCouverture.lire(racine));

                let statut = 0;
                let resultat = null;
                try {
                    const reponse = await fetch(window.location.pathname, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(donnees),
                    });
                    statut = reponse.status;
                    resultat = await reponse.json();
                } catch (erreur) {
                    // Réseau coupé, session expirée (redirection HTML) ou
                    // erreur serveur sans JSON : on l'affiche au lieu de
                    // laisser le bénévole croire que c'est enregistré.
                    resultat = null;
                }

                const ok = !!(resultat && resultat.success);
                window.VehiculeCouverture.afficherErreurs(racine, ok ? {} : (resultat && resultat.errors) || {});

                let texte = 'Disponibilité enregistrée.';
                if (!ok) {
                    const erreurs = (resultat && resultat.errors) || {};
                    texte = (erreurs.creneaux && erreurs.creneaux[0])
                        ? 'Sélectionnez au moins un créneau.'
                        : (resultat && resultat.errors
                            ? 'Certains champs sont invalides — voir ci-dessus.'
                            : "Une erreur s'est produite" + (statut ? ' (code ' + statut + ').' : ' — vérifiez votre connexion.'));
                }

                message.classList.remove('hidden');
                message.textContent = texte;
                message.className = 'disponibilite-message text-[13px] text-center ' + (ok ? 'text-emerald-600' : 'text-rose-600');
            });
        });
    </script>
@endsection
