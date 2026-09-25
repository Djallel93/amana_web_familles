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
            {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
            <span class="text-ink-muted font-normal">— dossier #{{ $livraison->famille->id }}</span>
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
                    default => 'bg-stone-100 text-ink-muted',
                } }}">
                {{ match($livraison->statutRetraitHqAffiche) {
                    'delivre' => 'Livré',
                    'non_delivre' => 'Non livré',
                    'prete' => 'Prête',
                    default => 'En préparation',
                } }}
            </span>
        </div>
    </div>

    <p class="text-[12px] text-ink-muted mb-3">
        {{ $livraison->heure_arrivee_prevue_hq?->translatedFormat('H:i') ?? 'Créneau pas encore planifié' }}
        @if($livraison->famille->etudiant)<span class="text-sky-600"> · étudiant</span>@endif
        @if($livraison->famille->est_hotel)<span class="text-amber-600"> · hôtel</span>@endif
        @if($livraison->famille->nombre_enfant > 0)<span class="text-violet-600"> · {{ $livraison->famille->nombre_enfant }} enfant(s)</span>@endif
    </p>

    {{--
        Boutons actifs UNIQUEMENT au statut "prete" (prompt §3) — masqués
        (pas juste désactivés) le reste du temps, même principe que
        chargement-route.blade.php pour 'chargement'.
    --}}
    <div class="flex gap-2" @if($livraison->statutRetraitHqAffiche !== 'prete') style="display:none" @endif>
        <button type="button" onclick="marquerLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Livré</button>
        <button type="button" onclick="marquerNonLivre({{ $livraison->id }})"
            class="text-[12px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600">Non livré (absent)</button>
    </div>
</div>
