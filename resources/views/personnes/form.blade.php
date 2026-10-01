{{-- resources/views/personnes/form.blade.php --}}
@extends('layouts.app')

@section('title', ($personne ? 'Modifier' : 'Ajouter') . ' une personne — AMANA Familles')

@section('content')

    <div class="max-w-xl mx-auto">

        <div class="mb-7">
            <a href="{{ route('admin.personnes.index') }}"
                class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg hover:opacity-90 no-underline">
                ← Retour à la liste
            </a>
            {{--
                Bouton conditionnel (24/09/2026, prompt de cette date
                §1.1 ; libellé et style plein bg-ink alignés sur les autres
                boutons « Retour à la campagne » le 01/10/2026) : affiché UNIQUEMENT si $urlRetour a été validé côté
                serveur (voir PersonnesController::infosRetour()) — un
                accès depuis la sidebar (create() ne passe même pas cette
                variable, edit() sans ?retour= la laisse null) n'affiche
                jamais ce second bouton.
            --}}
            @isset($urlRetour)
                <a href="{{ $urlRetour }}"
                    class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg hover:opacity-90 no-underline ml-2">
                    ← Retour à la campagne
                </a>
            @endisset
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-2">
                {{ $personne ? 'Modifier ' . $personne->prenom . ' ' . $personne->nom : 'Ajouter une personne' }}
            </h1>
            <p class="text-[13px] text-ink-muted mt-1">
                @if($personne)
                    Modifier les informations et le rôle sur AMANA Familles.
                @else
                    Si l'adresse email correspond à un compte AMANA existant (ex : déjà staff Planning), le rôle
                    Familles lui est simplement ajouté — aucun doublon n'est créé.
                @endif
            </p>
        </div>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <form action="{{ $personne ? route('admin.personnes.update', $personne->id) : route('admin.personnes.store') }}"
                method="POST">
                @csrf
                @if($personne) @method('PUT') @endif

                {{--
                    Reconduit retour/id_campagne au submit — PersonnesController::
                    update() les revalide via la même infosRetour() que
                    edit() (jamais fait confiance à $urlRetour tel quel),
                    pour rediriger vers campagnes/{id}/benevoles après
                    enregistrement (prompt §1.1 : "after saving, redirect
                    back to campagnes/{id}/benevoles too").
                --}}
                @if(request('retour'))
                    <input type="hidden" name="retour" value="{{ request('retour') }}">
                    <input type="hidden" name="id_campagne" value="{{ request('id_campagne') }}">
                @endif

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="prenom" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Prénom</label>
                        <input type="text" id="prenom" name="prenom" value="{{ old('prenom', $personne->prenom ?? '') }}"
                            required autofocus
                            class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                    focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        @error('prenom')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label for="nom" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Nom</label>
                        <input type="text" id="nom" name="nom" value="{{ old('nom', $personne->nom ?? '') }}" required
                            class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                    focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        @error('nom')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Adresse email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $personne->email ?? '') }}"
                        {{ $personne ? 'readonly' : 'required' }}
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]
                                {{ $personne ? 'opacity-60 cursor-not-allowed' : '' }}">
                    @if($personne)
                        <p class="text-[11.5px] text-ink-muted mt-1">L'adresse email d'un compte existant ne peut pas être modifiée ici.</p>
                    @endif
                    @error('email')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="mb-4">
                    <label for="telephone" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Téléphone</label>
                    <input type="text" id="telephone" name="telephone" value="{{ old('telephone', $personne->telephone ?? '') }}"
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                    @error('telephone')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="mb-6">
                    <label for="role" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Rôle sur AMANA Familles</label>
                    <select id="role" name="role" required onchange="document.getElementById('bloc-organisations').classList.toggle('hidden', this.value !== 'gestionnaire_externe')"
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        <option value="" disabled {{ !old('role', $roleActuel) ? 'selected' : '' }}>Sélectionner un rôle…</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->code }}" {{ old('role', $roleActuel) === $role->code ? 'selected' : '' }}>
                                {{ $role->libelle }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-[11.5px] text-ink-muted mt-1">
                        Permissions fines à affiner au fil des écrans — pour l'instant admin/gestionnaire ont accès
                        complet, membre/bénévole sont réservés pour un usage futur. Gestionnaire (organisation
                        partenaire) ne voit et ne gère que les dossiers de sa (ses) organisation(s) — voir ci-dessous.
                    </p>
                    @error('role')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                {{--
                    Multi-select organisations (ajouté le 28/08/2026) — affiché
                    uniquement pour le rôle gestionnaire_externe (voir toggle JS
                    ci-dessus sur #role), masqué par défaut sinon. Pas de <select
                    multiple> classique (peu ergonomique) : liste de checkboxes,
                    même esprit que les secteurs d'activité du formulaire d'intake.
                --}}
                <div id="bloc-organisations" class="mb-6 {{ old('role', $roleActuel) === 'gestionnaire_externe' ? '' : 'hidden' }}">
                    <label class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Organisation(s)</label>
                    <div class="border-[1.5px] border-ink-faint rounded-lg bg-surface-2 p-3 space-y-2 max-h-48 overflow-y-auto">
                        @forelse($organisations as $organisation)
                            <label class="flex items-center gap-2 text-[13.5px] text-ink cursor-pointer">
                                <input type="checkbox" name="organisations[]" value="{{ $organisation->id }}"
                                    {{ in_array($organisation->id, old('organisations', $organisationsActuelles)) ? 'checked' : '' }}
                                    class="rounded border-ink-faint">
                                {{ $organisation->nom }}
                            </label>
                        @empty
                            <p class="text-[12.5px] text-ink-muted">Aucune organisation active — créez-en une depuis Paramètres.</p>
                        @endforelse
                    </div>
                    @error('organisations')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                        class="flex-1 min-h-[46px] px-6 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-[13.5px] rounded-lg
                                shadow-[0_3px_12px_rgba(180,83,9,0.3)] hover:-translate-y-px active:translate-y-0 transition-all cursor-pointer">
                        {{ $personne ? '💾 Enregistrer' : '✉️ Créer et envoyer l\'invitation' }}
                    </button>
                    <a href="{{ route('admin.personnes.index') }}"
                        class="min-h-[46px] px-5 py-2.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[13.5px] font-semibold rounded-lg
                                transition-colors no-underline flex items-center">
                        Annuler
                    </a>
                </div>
            </form>
        </div>

        {{--
            « Par campagne / journée » (01/10/2026) : permis, véhicule et
            secteurs couverts sont propres à chaque campagne/journée (le
            permis peut être obtenu/perdu, le véhicule change) — plus
            éditables sur le profil. Carte HORS du <form> principal (pas de
            formulaires imbriqués) : elle enregistre seule, par fetch, vers
            PersonnesController::majDisponibilite(). Une modification admin
            vaut confirmation de la disponibilité.
        --}}
        @if($personne && $benevoleProfil)
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 mt-6" id="carte-journee">
                <h2 class="font-heading text-[14px] font-semibold text-ink mb-1">🚗 Par campagne / journée</h2>
                <p class="text-[13px] text-ink-muted mb-4">
                    Véhicule et zone couverte de {{ $personne->prenom }} pour la journée choisie. Enregistrer vaut confirmation de sa disponibilité.
                </p>

                @if(empty($groupesJournees))
                    <p class="text-[13px] text-ink-muted">Aucune campagne n'existe encore.</p>
                @else
                    <label for="select-journee" class="block text-[11.5px] font-semibold text-ink-muted mb-1">Campagne / date</label>
                    <select id="select-journee"
                        class="w-full px-3.5 py-2.5 mb-5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        @foreach($groupesJournees as $groupe)
                            <optgroup label="{{ $groupe['libelle'] }}">
                                @foreach($groupe['journees'] as $j)
                                    <option value="{{ $j['id'] }}" @selected($j['id'] === $journeeSelectionnee)>{{ $j['libelle'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>

                    @include('livraison.partials.vehicule-couverture', [
                        'vehicules' => $vehicules,
                        'villes' => $villes,
                        'profil' => $benevoleProfil,
                        'etat' => [],
                    ])

                    <div class="flex items-center gap-3 mt-5">
                        <button type="button" id="btn-enregistrer-journee"
                            class="min-h-[46px] px-6 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-[13.5px] rounded-lg cursor-pointer">
                            💾 Enregistrer cette journée
                        </button>
                        <span id="message-journee" class="text-[13px] hidden"></span>
                    </div>
                @endif
            </div>

            @unless(empty($groupesJournees))
                @include('livraison.partials.vehicule-couverture-script')
                <script>
                    (function () {
                        const carte = document.getElementById('carte-journee');
                        const racine = carte.querySelector('[data-vc-root]');
                        const select = document.getElementById('select-journee');
                        const bouton = document.getElementById('btn-enregistrer-journee');
                        const message = document.getElementById('message-journee');
                        const etats = @json($etatsJournees);
                        const urlBase = @json(route('admin.personnes.disponibilite.update', ['id' => $personne->id, 'journee' => 0]));
                        const jeton = document.querySelector('meta[name="csrf-token"]')?.content
                            ?? @json(csrf_token());

                        function afficher(texte, ok) {
                            message.textContent = texte;
                            message.className = 'text-[13px] ' + (ok ? 'text-emerald-600' : 'text-rose-600');
                        }

                        function charger() {
                            message.className = 'hidden';
                            window.VehiculeCouverture.appliquer(racine, etats[select.value] || {});
                        }

                        select.addEventListener('change', charger);
                        charger();

                        bouton.addEventListener('click', async function () {
                            bouton.disabled = true;
                            const idJournee = select.value;
                            let resultat = null;
                            let statut = 0;
                            try {
                                const reponse = await fetch(urlBase.replace(/\/0$/, '/' + idJournee), {
                                    method: 'PUT',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': jeton,
                                    },
                                    body: JSON.stringify(window.VehiculeCouverture.lire(racine)),
                                });
                                statut = reponse.status;
                                resultat = await reponse.json();
                            } catch (e) {
                                resultat = null;
                            }

                            const ok = !!(resultat && resultat.success);
                            window.VehiculeCouverture.afficherErreurs(racine, ok ? {} : (resultat && resultat.errors) || {});
                            if (ok) {
                                etats[idJournee] = resultat.etat;
                                const option = select.querySelector('option[value="' + idJournee + '"]');
                                if (option && !option.textContent.trim().endsWith('✓')) option.textContent = option.textContent.trim() + ' ✓';
                                afficher('Enregistré — disponibilité confirmée.', true);
                            } else {
                                afficher(resultat && resultat.errors
                                    ? 'Certains champs sont invalides — voir ci-dessus.'
                                    : "Une erreur s'est produite" + (statut ? ' (code ' + statut + ').' : ' — vérifiez la connexion.'), false);
                            }
                            bouton.disabled = false;
                        });
                    })();
                </script>
            @endunless
        @endif

        {{-- Mot de passe : un administrateur n'en saisit ni n'en voit jamais — il envoie un lien. --}}
        @if($personne)
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 mt-6">
                <h2 class="font-heading text-[14px] font-semibold text-ink mb-1">🔑 Mot de passe</h2>
                <p class="text-[13px] text-ink-muted mb-4">
                    Vous ne pouvez pas définir le mot de passe de cette personne : envoyez-lui un lien pour qu'elle le crée
                    ou le réinitialise elle-même. L'email part à <strong class="text-ink">{{ $personne->email }}</strong>.
                </p>
                <form action="{{ route('admin.personnes.reset-link', $personne->id) }}" method="POST">
                    @csrf
                    <button type="submit"
                        class="min-h-[46px] px-5 py-2.5 border-[1.5px] border-accent text-accent hover:bg-accent hover:text-white text-[13.5px] font-bold rounded-lg transition-colors cursor-pointer">
                        ✉️ Envoyer un lien de réinitialisation
                    </button>
                </form>
            </div>
        @endif
    </div>

@endsection
