{{-- resources/views/livraison/partials/retrait-hq-ligne.blade.php --}}
{{--
    Carte d'UNE famille se_deplace sur l'écran Retrait QG — pendant de
    chargement-route.blade.php, voir RetraitHqController::construireListe()
    (24/09/2026, prompt de cette date §2/§Additional points 2). Attend :
    $livraison (avec ->famille chargée et ->statutRetraitHqAffiche déjà
    calculé par le contrôleur).
--}}
<div class="bg-surface border border-surface-border rounded-xl p-4"
    id="retrait-{{ $livraison->id }}" data-statut="{{ $livraison->statutRetraitHqAffiche }}">
    <div class="flex items-center justify-between mb-2">
        <span class="text-[14px] font-medium text-ink">
            #{{ $livraison->famille->id }} {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
        </span>
        <div class="flex items-center gap-1.5">
            {{--
                Additional points 2 du prompt : "add a pill indicating if
                this person will have a qr code or will just present an
                ID" — dérivé de la présence d'un email (seul canal
                d'envoi du QR, voir RetraitHqNotificationService), pas
                d'un champ séparé.
            --}}
            @if($livraison->famille->email)
                <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">📱 QR code</span>
            @else
                <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-stone-100 text-ink-muted">🪪 Pièce d'identité</span>
            @endif
            <span class="statut-retrait-hq text-[11px] font-medium px-2 py-0.5 rounded-full
                {{ match($livraison->statutRetraitHqAffiche) {
                    'delivre' => 'bg-emerald-100 text-emerald-700',
                    'non_delivre' => 'bg-rose-100 text-rose-700',
                    'prete' => 'bg-accent/10 text-accent',
                    'en_preparation' => 'bg-amber-100 text-amber-700',
                    default => 'bg-stone-100 text-ink-muted',
                } }}">
                {{ match($livraison->statutRetraitHqAffiche) {
                    'delivre' => 'Livré',
                    'non_delivre' => 'Non livré',
                    'prete' => 'Prête',
                    'en_preparation' => 'En préparation',
                    default => 'Restante',
                } }}
            </span>
        </div>
    </div>

    <p class="text-[12px] text-ink-muted mb-2">
        🕒 {{ $livraison->heure_arrivee_prevue_hq?->translatedFormat('H:i') ?? 'Créneau pas encore planifié' }}
    </p>

    {{-- Pastilles « émoji + nombre + libellé » (octobre 2026), mêmes règles que l'écran Chargement. --}}
    <div class="flex flex-wrap gap-1.5 mb-2 text-[11px] font-medium">
        @if($livraison->famille->etudiant)<span class="px-2 py-0.5 rounded-full bg-sky-50 text-sky-700">🎓 Étudiant</span>@endif
        @if($livraison->famille->est_hotel)<span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">🏨 Hôtel</span>@endif
        @if($livraison->famille->nombre_enfant > 0)<span class="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700">👶 {{ $livraison->famille->nombre_enfant }} {{ $livraison->famille->nombre_enfant > 1 ? 'enfants' : 'enfant' }}</span>@endif
        @if($livraison->nombre_colis > 0)<span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-700">📦 {{ $livraison->nombre_colis }} colis</span>@endif
    </div>

    {{-- Coordonnées (octobre 2026) : téléphones et email quand ils existent. --}}
    <p class="mb-3 flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px]">
        @foreach(array_filter([$livraison->famille->telephone, $livraison->famille->telephone_bis]) as $numero)
            <a href="tel:{{ preg_replace('/[^\d+]/', '', $numero) }}" class="text-accent underline whitespace-nowrap">📞 {{ \App\Models\Famille::formaterTelephoneFr($numero) }}</a>
        @endforeach
        @if($livraison->famille->email)
            <a href="mailto:{{ $livraison->famille->email }}" class="text-accent underline break-all">✉️ {{ $livraison->famille->email }}</a>
        @endif
    </p>

    {{-- Préparation prioritaire (11/10/2026) : tant que le colis n'est pas prêt. Ne se retire pas. --}}
    @if($livraison->statutRetraitHqAffiche !== 'prete' && !in_array($livraison->statutRetraitHqAffiche, ['delivre', 'non_delivre'], true))
        <div class="mb-2">
            @if($livraison->preparation_prioritaire)
                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-indigo-600 text-white">⭐ Préparation prioritaire demandée</span>
            @else
                <button type="button" onclick="demanderPreparationPrioritaire({{ $livraison->id }})"
                    class="text-[12px] px-3 py-1.5 rounded-lg border border-indigo-300 text-indigo-700 hover:bg-indigo-50">⭐ Préparation prioritaire</button>
            @endif
        </div>
    @endif

    {{--
        Boutons actifs UNIQUEMENT au statut "prete" (prompt §3) — masqués
        (pas juste désactivés) le reste du temps, même principe que
        chargement-route.blade.php pour 'chargement'.
    --}}
    <div class="flex gap-2 actions-prete" @if($livraison->statutRetraitHqAffiche !== 'prete') style="display:none" @endif>
        <button type="button" onclick="marquerLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Livré</button>
        <button type="button" onclick="marquerNonLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600">Non livré (absent)</button>
    </div>

    {{-- « Livré » marqué par erreur (23/10/2026) : retour à « Prête », avec confirmation. --}}
    <div class="flex gap-2 actions-livre" @if($livraison->statutRetraitHqAffiche !== 'delivre') style="display:none" @endif>
        <button type="button" onclick="annulerLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg border border-accent text-accent">Annuler « Livré »</button>
    </div>

    {{-- « Non livré » marqué par erreur (09/10/2026) : retour à « Prête » (l'incident ouvert est résolu avec). --}}
    <div class="flex gap-2 actions-non-livre" @if($livraison->statutRetraitHqAffiche !== 'non_delivre') style="display:none" @endif>
        <button type="button" onclick="annulerNonLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg border border-accent text-accent">Annuler « Non livré »</button>
    </div>
</div>
