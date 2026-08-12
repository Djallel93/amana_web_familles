<?php
// app/Http/Controllers/FamillesController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Models\Quartier;
use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Ville;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Vue principale des dossiers familles (section 8.2 du prompt de migration) :
 * barre de filtres en haut, tableau compact, panneau de détail/édition au
 * clic sur une ligne (slide-over Vue, voir resources/js/components/familles/
 * DetailPanel.vue), consultation/upload des documents intégrée au panneau.
 *
 * index() reste un rendu Blade classique (filtres server-side, pagination
 * Laravel standard) — seul le panneau de détail est un îlot Vue consommant
 * show()/update() en JSON, pattern différent de celui des pages
 * statistiques (Blade + Vue + Chart.js) mais cohérent avec l'esprit
 * "Blade en coquille, Vue pour l'interactif" du reste de l'app.
 */
class FamillesController extends Controller
{
    /**
     * Colonnes triables du tableau "Dossiers familles" (voir
     * resources/views/familles/index.blade.php) — whitelist explicite
     * plutôt que d'accepter un nom de colonne SQL arbitraire depuis la
     * requête (paramètre ?tri=...).
     */
    private const COLONNES_TRIABLES = [
        'id', 'nom', 'statut', 'email', 'telephone', 'telephone_bis', 'adresse',
        'nombre_adulte', 'nombre_enfant', 'criticite', 'eligibilite', 'se_deplace',
        'est_hotel', 'langue', 'type_hebergement', 'type_piece_identite',
        'type_activite', 'work_days', 'created_at',
    ];

    public function index(Request $request): View
    {
        $query = $this->baseQuery($request);

        // Filtre statut — par défaut "Validé" au premier chargement (aucun
        // paramètre etat_dossier présent dans l'URL). Le lien de réinitialisation
        // (✕) envoie explicitement etat_dossier= (vide) pour signifier "Tous",
        // ce qui doit être distingué de l'absence du paramètre. 'Recu' n'est
        // volontairement plus dans les valeurs possibles ici — géré
        // exclusivement par nouvelles() ci-dessous (décision du 09/08/2026).
        $etatDossier = $request->has('etat_dossier') ? $request->input('etat_dossier') : 'Validé';
        if ($etatDossier !== '' && $etatDossier !== null) {
            $query->where('etat_dossier', $etatDossier);
        } else {
            $query->where('etat_dossier', '!=', 'Recu');
        }

        $this->appliquerTri($query, $request);

        $familles = $query->paginate(25)->withQueryString();

        // Stats du bandeau KPI en haut de page — recalculées sur les mêmes
        // filtres de recherche/localisation que la liste (baseQuery), mais
        // volontairement SANS la restriction de statut ci-dessus : donne une
        // vue d'ensemble utile même quand on ne regarde qu'un seul statut à
        // la fois (demande du 12/08/2026).
        $statsQuery = $this->baseQuery($request);
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'moyenne_criticite' => (float) ((clone $statsQuery)->avg('criticite') ?? 0),
            // "À traiter en priorité" : dossiers avec un problème de
            // traitement signalé (échec géocodage, etc.), ou une criticité
            // élevée sur un dossier pas encore refermé (Validé/Rejeté/
            // Archivé = déjà traité, peu importe sa criticité).
            'a_traiter' => (clone $statsQuery)
                ->where(function ($q) {
                    $q->whereNotNull('probleme_traitement')
                        ->orWhere(function ($q2) {
                            $q2->where('criticite', '>=', 4)
                                ->whereNotIn('etat_dossier', ['Validé', 'Rejeté', 'Archivé']);
                        });
                })
                ->count(),
            'par_statut' => (clone $statsQuery)
                ->selectRaw('etat_dossier, count(*) as total')
                ->groupBy('etat_dossier')
                ->pluck('total', 'etat_dossier'),
        ];

        // Filtres géographiques — listes complètes indépendamment des
        // résultats courants (villes/secteurs/quartiers sont créées vides
        // pour l'instant, cf. décision 6.7 : ces selects seront vides tant
        // que le peuplement des polygones n'est pas fait).
        $villes = Ville::orderBy('nom')->get(['id', 'nom']);
        $secteurs = Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']);
        // secteur:id,id_ville eager-loadé pour le filtre Ville → Quartier en
        // cascade côté front (data-id-ville sur chaque <option>, voir
        // familles/index.blade.php) — Quartier n'a pas de colonne id_ville
        // directe, seulement via secteur (cf. Amana\Shared\Models\Quartier).
        $quartiers = Quartier::with('secteur:id,id_ville')->orderBy('nom')->get(['id', 'nom', 'id_secteur']);

        return view('familles.index', compact('familles', 'villes', 'secteurs', 'quartiers', 'etatDossier', 'stats'));
    }

    /**
     * "Nouvelles demandes" — file d'attente des dossiers pas encore
     * ouverts par le staff (etat_dossier = 'Recu', réservé aux soumissions
     * du formulaire public — voir Famille::ETATS_MODIFIABLES). Vue dédiée
     * plutôt qu'un simple lien filtré vers index() : tri par ancienneté
     * (le plus vieux d'abord, pas par criticité comme la liste générale,
     * pour qu'aucune demande ne reste oubliée), et met en évidence
     * probleme_traitement (échecs de géocodage notamment) — demande du
     * 09/08/2026.
     */
    public function nouvelles(Request $request): View
    {
        $query = $this->baseQuery($request)->where('etat_dossier', 'Recu');

        $familles = $query->orderBy('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('familles.nouvelles', compact('familles'));
    }

    /**
     * Base commune à index() et nouvelles() — seuls le filtre de statut et
     * le tri diffèrent entre les deux vues.
     */
    private function baseQuery(Request $request)
    {
        $query = Famille::query()->with('quartier.secteur.ville');

        if ($request->filled('id_quartier')) {
            $query->where('id_quartier', $request->input('id_quartier'));
        }
        if ($request->filled('id_secteur')) {
            $query->whereHas('quartier', fn($q) => $q->where('id_secteur', $request->input('id_secteur')));
        }
        if ($request->filled('id_ville')) {
            $query->whereHas('quartier.secteur', fn($q) => $q->where('id_ville', $request->input('id_ville')));
        }
        if ($request->boolean('zakat_el_fitr')) {
            $query->where('zakat_el_fitr', true);
        }
        if ($request->boolean('sadaqa')) {
            $query->where('sadaqa', true);
        }
        if ($request->filled('se_deplace')) {
            $query->where('se_deplace', $request->input('se_deplace') === '1');
        }
        if ($request->filled('criticite_min')) {
            $query->where('criticite', '>=', (int) $request->input('criticite_min'));
        }
        if ($request->filled('criticite_max')) {
            $query->where('criticite', '<=', (int) $request->input('criticite_max'));
        }
        if ($request->filled('recherche')) {
            $query->recherche($request->input('recherche'));
        }

        return $query;
    }

    /**
     * Tri du tableau "Dossiers familles" (?tri=colonne&direction=asc|desc,
     * en-têtes cliquables — voir familles/index.blade.php). Sans paramètre
     * ?tri reconnu, tri par ID croissant (demande du 12/08/2026 — remplace
     * l'ancien défaut criticité décroissante).
     *
     * 'eligibilite' n'est pas une colonne unique en base (zakat_el_fitr +
     * sadaqa sont deux booléens distincts) — trié comme un score combiné :
     * zakat_el_fitr d'abord, puis sadaqa, dans la même direction.
     */
    private function appliquerTri($query, Request $request): void
    {
        $colonne = $request->input('tri');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        if (!in_array($colonne, self::COLONNES_TRIABLES, true)) {
            $query->orderBy('id');

            return;
        }

        match ($colonne) {
            'id' => $query->orderBy('id', $direction),
            'nom' => $query->orderBy('nom', $direction)->orderBy('prenom', $direction),
            'statut' => $query->orderBy('etat_dossier', $direction),
            'email' => $query->orderBy('email', $direction),
            'telephone' => $query->orderBy('telephone', $direction),
            'telephone_bis' => $query->orderBy('telephone_bis', $direction),
            'adresse' => $query->orderBy('adresse', $direction),
            'nombre_adulte' => $query->orderBy('nombre_adulte', $direction),
            'nombre_enfant' => $query->orderBy('nombre_enfant', $direction),
            'criticite' => $query->orderBy('criticite', $direction),
            'eligibilite' => $query->orderBy('zakat_el_fitr', $direction)->orderBy('sadaqa', $direction),
            'se_deplace' => $query->orderBy('se_deplace', $direction),
            'est_hotel' => $query->orderBy('est_hotel', $direction),
            'langue' => $query->orderBy('langue', $direction),
            'type_hebergement' => $query->orderBy('type_hebergement', $direction),
            'type_piece_identite' => $query->orderBy('type_piece_identite', $direction),
            'type_activite' => $query->orderBy('type_activite', $direction),
            'work_days' => $query->orderBy('work_days', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
        };
    }

    // ── Panneau de détail (consommé en JSON par DetailPanel.vue) ─────────

    public function show(int $id): JsonResponse
    {
        $famille = Famille::with(['quartier.secteur.ville', 'documents'])->findOrFail($id);

        // quartier.boundary / quartier.secteur.ville.boundary sont des colonnes
        // geometry (WKB binaire) — jamais de l'UTF-8 valide. Sans ça,
        // response()->json() plante avec "Malformed UTF-8 characters" dès
        // qu'une famille a un quartier résolu (voir CHANGELOG). Fix propre
        // en amont dans amana_shared (Quartier/Ville::$hidden) ; ceci reste
        // en filet de sécurité local le temps que ce paquet soit mis à jour.
        $famille->quartier?->makeHidden('boundary');
        $famille->quartier?->secteur?->ville?->makeHidden('boundary');

        return response()->json($famille);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);

        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'prenom' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'telephone_bis' => ['nullable', 'string', 'max:30'],
            'zakat_el_fitr' => ['boolean'],
            'sadaqa' => ['boolean'],
            'nombre_adulte' => ['required', 'integer', 'min:0', 'max:255'],
            'nombre_enfant' => ['required', 'integer', 'min:0', 'max:255'],
            'adresse' => ['required', 'string'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville_texte' => ['nullable', 'string', 'max:150'],
            'id_quartier' => ['nullable', 'integer', 'exists:commun.quartiers,id'],
            'se_deplace' => ['boolean'],
            'circonstances' => ['nullable', 'string'],
            'ressentit' => ['nullable', 'string'],
            'specificites' => ['nullable', 'string'],
            'criticite' => ['required', 'integer', 'min:0', 'max:5'],
            'langue' => ['required', 'string', 'in:fr,ar,en'],
            'etat_dossier' => ['required', 'string', 'in:' . implode(',', Famille::ETATS_MODIFIABLES)],
            'commentaire_dossier' => ['nullable', 'string'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'criticite.max' => 'La criticité doit être comprise entre 0 et 5.',
            'etat_dossier.in' => 'Statut de dossier invalide.',
        ]);

        $avant = $famille->toArray();
        $etaitValide = $famille->etat_dossier === 'Validé';
        // Détecté AVANT fill() : sert à décider si on relance le géocodage
        // après l'enregistrement (voir plus bas).
        $adresseModifiee = $famille->adresse !== $validated['adresse']
            || $famille->code_postal !== ($validated['code_postal'] ?? null)
            || $famille->ville_texte !== ($validated['ville_texte'] ?? null);

        $famille->fill($validated);
        // Le staff a renseigné/corrigé le quartier manuellement — un
        // éventuel signalement d'échec de géocodage n'a plus lieu d'être
        // (demande du 09/08/2026 : le badge rouge doit disparaître une fois
        // le problème résolu, pas rester affiché indéfiniment).
        if ($request->filled('id_quartier')) {
            $famille->probleme_traitement = null;
        }
        $famille->save();

        // Adresse corrigée par le staff (ex : suite à un ZERO_RESULTS
        // signalé dans probleme_traitement) et aucun quartier choisi
        // manuellement dans le même enregistrement : on relance la
        // résolution automatique plutôt que de laisser le badge rouge
        // affiché indéfiniment sans action possible — demande du
        // 09/08/2026 (le message affiché dans DetailPanel.vue promet
        // explicitement ce comportement).
        if ($adresseModifiee && !$request->filled('id_quartier')) {
            ResoudreAdresseFamille::dispatch($famille->id);
        }

        audit('update', 'familles', $famille->id, $avant, $famille->toArray());

        // Synchronisation contact Google — uniquement au moment où le
        // dossier PASSE à 'Validé' (transition, pas à chaque sauvegarde
        // d'un dossier déjà validé), décision 6.5. Depuis le 17/07/2026,
        // intégration directe People API (SynchroniserContactGoogle) au
        // lieu du webhook Make.com — le job détermine lui-même s'il doit
        // créer ou mettre à jour le contact via google_resource_name.
        // Une édition ultérieure d'un dossier déjà validé repasse
        // etat_dossier à 'En cours' (formulaire), donc chaque nouvelle
        // validation redéclenche naturellement ce job (en updateContact
        // cette fois) — pas besoin d'un second point de déclenchement.
        if (!$etaitValide && $famille->etat_dossier === 'Validé') {
            \App\Jobs\SynchroniserContactGoogle::dispatch($famille->id);
        }

        return response()->json($famille->fresh(['quartier.secteur.ville', 'documents']));
    }

    // ── Documents (consultation/upload — décision 6.4, stockage disque local) ──

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);

        $request->validate([
            'type' => ['required', 'string', 'in:identity,caf,ame,resource'],
            'fichier' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'fichier.required' => 'Aucun fichier sélectionné.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'fichier.mimes' => 'Formats acceptés : PDF, JPG, PNG.',
        ]);

        $file = $request->file('fichier');
        $path = $file->store("familles/{$famille->id}", 'local');

        $document = FamilleDocument::create([
            'id_famille' => $famille->id,
            'type' => $request->input('type'),
            'disk_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_at' => now(),
        ]);

        audit('create', 'familles_documents', $document->id, null, $document->toArray());

        return response()->json($document, 201);
    }

    public function downloadDocument(int $id, int $documentId)
    {
        $document = FamilleDocument::where('id_famille', $id)->findOrFail($documentId);

        if (!Storage::disk('local')->exists($document->disk_path)) {
            abort(404, 'Fichier introuvable sur le disque.');
        }

        return Storage::disk('local')->download($document->disk_path, $document->original_name);
    }

    public function destroyDocument(int $id, int $documentId): JsonResponse
    {
        $document = FamilleDocument::where('id_famille', $id)->findOrFail($documentId);
        $avant = $document->toArray();

        Storage::disk('local')->delete($document->disk_path);
        $document->delete();

        audit('delete', 'familles_documents', $documentId, $avant, null);

        return response()->json(['deleted' => true]);
    }
}

