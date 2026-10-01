{{-- resources/views/livraison/partials/packaging-ligne.blade.php --}}
{{--
    Carte d'une famille de la file Packaging — extraite de packaging.blade.php
    le 30/09/2026 pour être rendue à l'identique par index() ET par le
    polling (PackagingController::liste()) : la signature md5 du HTML permet
    au client de ne remplacer que les cartes réellement modifiées (même
    principe que partials/chargement-route.blade.php).
--}}
        {{--
            Teinte légère étudiant/hôtel en plus des badges
            (05/09/2026, prompt §4.5) — hôtel prioritaire si une
            famille est (en théorie jamais) les deux à la fois,
            simple choix arbitraire plutôt que de mélanger les
            teintes. Conservée telle quelle (09/09/2026, prompt de
            cette date §2.2 : "keep current coloring") — la bordure
            d'urgence ci-dessous s'ajoute par-dessus plutôt que de
            la remplacer, ce sont deux dimensions indépendantes
            (voir PackagingController::calculerUrgencePackaging()).
        --}}
        <div class="border rounded-xl p-4
            {{ $livraison->urgente ? 'border-rose-600 border-l-8' : 'border-surface-border' }}
            {{ $livraison->famille->est_hotel ? 'bg-amber-50' : ($livraison->famille->etudiant ? 'bg-sky-50' : 'bg-surface') }}"
            id="livraison-{{ $livraison->id }}">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    {{--
                        Case "famille entière" (prompt §5.3) : reste
                        cliquable en permanence (voir toggleFamille()
                        ci-dessous) — cocher tous les colis la coche
                        automatiquement, la décocher déclenche une
                        confirmation avant d'annuler le
                        conditionnement complet. Grisée visuellement
                        (opacité) tant que tous les colis ne sont pas
                        prêts plutôt que l'attribut HTML `disabled`
                        (05/09/2026 : plus fiable après un
                        changement d'état dynamique en JS).
                    --}}
                    <input type="checkbox" class="mt-1 case-famille" data-id-livraison="{{ $livraison->id }}"
                        data-statut-conditionnement="{{ $livraison->statut_conditionnement }}"
                        {{ $livraison->statut_conditionnement === 'prete' ? 'checked' : '' }}
                        onchange="toggleFamille({{ $livraison->id }}, this.checked)">
                    <div>
                        {{--
                            Pas de nom/téléphone ici (05/09/2026,
                            prompt §4.4) : l'équipe packaging n'a
                            besoin que de l'id, du nombre
                            d'adultes/enfants et des besoins
                            spéciaux pour préparer les colis — pas
                            de l'identité de la famille.
                        --}}
                        <p class="text-[14px] font-medium text-ink">
                            Famille #{{ $livraison->famille->id }}
                            <span class="text-[12px] text-ink-muted">
                                — {{ $livraison->famille->nombre_adulte }} adulte(s), {{ $livraison->famille->nombre_enfant }} enfant(s)
                            </span>
                        </p>
                        <div class="flex gap-1.5 mt-1">
                            @if($livraison->famille->etudiant)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">Étudiant</span>
                            @endif
                            @if($livraison->famille->est_hotel)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Hôtel</span>
                            @endif
                            {{-- Ajouté le 09/09/2026 (prompt de cette date §2.3 : "If family has kids make it more visible") — violet comme le tag "· N enfant(s)" de chargement.blade.php, en gras pour ressortir davantage que les deux badges ci-dessus. --}}
                            @if($livraison->famille->nombre_enfant > 0)
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">👶 {{ $livraison->famille->nombre_enfant }} enfant(s)</span>
                            @endif
                        </div>
                        @if($livraison->note_besoins_speciaux)
                            <p class="text-[12px] text-rose-600 mt-1">⚠ {{ $livraison->note_besoins_speciaux }}</p>
                        @endif

                        {{-- Un colis = une personne du foyer (prompt §5.3). Verrouillées une fois la livraison 'prete' (09/09/2026, prompt de cette date §2.1) : voir PackagingController::marquerColisPret(), seule la case "famille entière" (confirmation + annulerConditionnement()) peut rouvrir les colis à ce stade. --}}
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($livraison->colis as $colis)
                                <label class="inline-flex items-center gap-1.5 text-[12px] px-2 py-1 rounded-lg border border-surface-border bg-white {{ $livraison->statut_conditionnement === 'prete' ? 'opacity-60' : '' }}">
                                    <input type="checkbox" class="case-colis" data-id-colis="{{ $colis->id }}" data-id-livraison="{{ $livraison->id }}"
                                        {{ $colis->statut === 'pret' ? 'checked' : '' }}
                                        {{ $livraison->statut_conditionnement === 'prete' ? 'disabled' : '' }}
                                        onchange="toggleColis({{ $colis->id }}, {{ $livraison->id }}, this.checked)">
                                    Colis {{ $colis->numero }}/{{ $livraison->nombre_personnes }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{--
                    Badge de statut déplacé en haut à droite + agrandi
                    (09/09/2026, prompt de cette date §4 : "Move
                    status (Restante, Terminée) to upper right corner
                    and make it a little bigger") — reprend la
                    position d'avant §6.1, seule la taille/position
                    changent ; mis à jour en JS par
                    appliquerStatutConditionnement(), toujours la
                    source de vérité de l'état affiché après un
                    toggle sans recharger la page.
                --}}
                {{-- Trois états depuis le 09/09/2026 (prompt de cette date §2.1, statut_conditionnement réellement à 3 valeurs désormais) — 'en_cours' inséré entre 'Restante' et 'Terminée', même palette ambre que le reste de l'app pour un état intermédiaire (voir chargement.blade.php/statuts route). --}}
                <span class="statut-conditionnement shrink-0 text-[13px] font-medium px-2.5 py-1 rounded-full
                    {{ match($livraison->statut_conditionnement) {
                        'prete' => 'bg-emerald-100 text-emerald-700',
                        'en_cours' => 'bg-amber-100 text-amber-700',
                        default => 'bg-stone-100 text-ink-muted',
                    } }}"
                    data-id-livraison="{{ $livraison->id }}">
                    {{ match($livraison->statut_conditionnement) {
                        'prete' => 'Terminée',
                        'en_cours' => 'En cours',
                        default => 'Restante',
                    } }}
                </span>
            </div>
        </div>
