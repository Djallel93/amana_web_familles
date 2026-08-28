<?php
// app/Http/Controllers/FamillesController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Models\Organisation;
use App\Models\OrganismeAide;
use App\Models\Personne;
use App\Models\Quartier;
use App\Models\SecteurActivite;
use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Ville;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        'est_hotel', 'etudiant', 'langue', 'type_piece_identite', 'created_at',
    ];

    /**
     * Valide le paramètre ?per_page= reçu en requête contre la whitelist
     * Famille::PAGINATION_PAR_PAGE — voir commentaire sur cette constante.
     * Partagé entre index() et nouvelles(), qui utilisent toutes deux le
     * sélecteur "lignes par page" du même composant de pagination.
     */
    private function resoudrePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', Famille::PAGINATION_PAR_PAGE_DEFAUT);

        return in_array($perPage, Famille::PAGINATION_PAR_PAGE, true)
            ? $perPage
            : Famille::PAGINATION_PAR_PAGE_DEFAUT;
    }

    public function index(Request $request): View
    {
        $query = $this->baseQuery($request);

        $etatDossier = $this->appliquerFiltreStatut($query, $request);

        $this->appliquerTri($query, $request);

        $familles = $query->paginate($this->resoudrePerPage($request))->withQueryString();


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

        // Listes fermées "Activité"/"Ressources" (mêmes tables que
        // IntakeController::showForm) — consommées par DetailPanel.vue pour
        // éditer secteursActivite/organismesAide dans l'onglet Situation,
        // pas seulement à la soumission initiale du formulaire public.
        $secteursActivite = SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);
        $organismesAide = OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);

        return view('familles.index', compact(
            'familles', 'villes', 'secteurs', 'quartiers', 'etatDossier',
            'secteursActivite', 'organismesAide',
        ));
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
            ->paginate($this->resoudrePerPage($request))
            ->withQueryString();

        // Mêmes listes que index() — cette vue monte le même DetailPanel.vue
        // (voir familles/nouvelles.blade.php), qui a besoin des mêmes
        // données pour l'onglet Situation.
        $secteursActivite = SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);
        $organismesAide = OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);

        return view('familles.nouvelles', compact('familles', 'secteursActivite', 'organismesAide'));
    }

    /**
     * Base commune à index() et nouvelles() — seuls le filtre de statut et
     * le tri diffèrent entre les deux vues.
     */
    private function baseQuery(Request $request)
    {
        $query = Famille::query()->with('quartier.secteur.ville');

        // Visibilité par organisation (ajouté le 28/08/2026) — réservé aux
        // comptes gestionnaire_externe : admin/gestionnaire/benevole/membre
        // continuent de tout voir, exactement comme avant cette
        // fonctionnalité (voir Famille::scopeVisiblePar()).
        $utilisateur = auth()->user();
        if ($utilisateur && $utilisateur->isGestionnaireExterne() && !$utilisateur->isAdmin() && !$utilisateur->isGestionnaire()) {
            $query->visiblePar(Organisation::idsPourPersonne($utilisateur->id));
        }

        if ($request->filled('id_quartier')) {
            $query->where('id_quartier', $request->input('id_quartier'));
        }
        // id_secteur / id_ville : Quartier/Secteur/Ville vivent sur la
        // connexion 'commun' (amana_commun), familles sur la connexion par
        // défaut (amana_familles) — voir Amana\Shared\Models\Quartier/
        // Secteur/Ville::getConnectionName(). whereHas() génère un
        // sous-select 'exists' en réutilisant tel quel le nom de table du
        // modèle lié, SANS qualifier la base : MySQL le résout alors dans
        // le schéma de la connexion du modèle PARENT (amana_familles) et
        // échoue avec "Table 'amana_familles.quartiers' doesn't exist"
        // (signalé le 13/08/2026). On résout donc les id_quartier
        // correspondants via une requête séparée sur la connexion
        // 'commun', puis un whereIn() classique sur familles.id_quartier —
        // deux requêtes mono-connexion plutôt qu'un exists() cross-DB.
        if ($request->filled('id_secteur')) {
            $query->whereIn('id_quartier', Quartier::where('id_secteur', $request->input('id_secteur'))->pluck('id'));
        }
        if ($request->filled('id_ville')) {
            $query->whereIn('id_quartier', Quartier::whereHas('secteur', fn($q) => $q->where('id_ville', $request->input('id_ville')))->pluck('id'));
        }
        if ($request->boolean('zakat_el_fitr')) {
            $query->where('zakat_el_fitr', true);
        }
        if ($request->boolean('sadaqa')) {
            $query->where('sadaqa', true);
        }
        // se_deplace / est_hotel / etudiant : cases à cocher simples (voir
        // familles/index.blade.php) — cochée = filtre sur "Oui" uniquement,
        // décochée = indifférent, même sémantique que zakat_el_fitr/sadaqa
        // ci-dessus (remplace le <select> Oui/Non/Indifférent à 3 états du
        // 13/08/2026 : pas de moyen de filtrer explicitement sur "Non" côté
        // UI désormais, jugé peu utile en pratique).
        if ($request->boolean('se_deplace')) {
            $query->where('se_deplace', true);
        }
        if ($request->boolean('est_hotel')) {
            $query->where('est_hotel', true);
        }
        if ($request->boolean('etudiant')) {
            $query->where('etudiant', true);
        }
        // Sélection discrète (cases à cocher 0-5, voir familles/index.blade.php)
        // plutôt qu'un intervalle min/max — remplace criticite_min/criticite_max
        // le 13/08/2026 (demande : pouvoir cocher ex. 3 ET 5 sans inclure 4).
        // Filtrage sur les entiers valides uniquement, silencieusement ignoré
        // sinon (paramètre trafiqué) plutôt que de faire échouer la requête.
        if ($request->filled('criticite')) {
            $valeurs = array_values(array_intersect(
                array_map('intval', (array) $request->input('criticite')),
                range(0, 5),
            ));
            if (!empty($valeurs)) {
                $query->whereIn('criticite', $valeurs);
            }
        }
        if ($request->filled('recherche')) {
            $query->recherche($request->input('recherche'));
        }
        // Nom / Téléphone (familles/index.blade.php) : deux champs distincts
        // avec autocomplétion — voir rechercheSuggestions() ci-dessous. Un
        // clic sur une suggestion pose id_selection (l'id exact de la
        // famille visée) plutôt que de compter sur le texte affiché dans le
        // champ pour matcher via LIKE : le champ est rempli avec le
        // nom/prénom ou le téléphone complet à des fins d'affichage
        // uniquement, id_selection prime donc sur nom/telephone quand les
        // deux sont présents. Un JS annule id_selection dès que l'utilisateur
        // retape dans le champ (voir le script en bas de la vue), pour
        // retomber sur une recherche LIKE classique.
        if ($request->filled('id_selection')) {
            $query->where('id', (int) $request->input('id_selection'));
        } else {
            if ($request->filled('nom')) {
                $query->rechercheNom($request->input('nom'));
            }
            if ($request->filled('telephone')) {
                $query->rechercheTelephone($request->input('telephone'));
            }
        }

        return $query;
    }

    /**
     * Défense en profondeur pour show()/update()/documents.* — baseQuery()
     * couvre déjà index()/nouvelles(), mais un accès direct par URL
     * (/familles/{id}) contourne le filtre de liste : sans ce garde, un
     * gestionnaire_externe pourrait ouvrir/modifier n'importe quel dossier
     * en devinant son ID. admin/gestionnaire/benevole/membre ne sont jamais
     * bloqués ici (accès complet inchangé depuis avant cette fonctionnalité).
     */
    private function assertAccesFamille(Famille $famille): void
    {
        $utilisateur = auth()->user();

        if (!$utilisateur->isGestionnaireExterne() || $utilisateur->isAdmin() || $utilisateur->isGestionnaire()) {
            return;
        }

        abort_unless(
            $famille->organisations()->whereIn('organisations.id', Organisation::idsPourPersonne($utilisateur->id))->exists(),
            403,
            "Vous n'avez pas accès à ce dossier.",
        );
    }

    /**
     * Filtre de statut avec le défaut "Validé" au premier chargement (aucun
     * paramètre etat_dossier présent dans l'URL) — le lien de
     * réinitialisation envoie explicitement etat_dossier= (vide) pour
     * signifier "Tous", ce qui doit être distingué de l'absence du
     * paramètre. 'Recu' n'est volontairement plus dans les valeurs
     * possibles ici — géré exclusivement par nouvelles() (décision du
     * 09/08/2026). Factorisé le 13/08/2026 (utilisé par index() et
     * export()) — retourne la valeur résolue pour que index() puisse encore
     * l'exposer à la vue (pastille de statut sélectionnée).
     */
    private function appliquerFiltreStatut($query, Request $request): string
    {
        $etatDossier = $request->has('etat_dossier') ? $request->input('etat_dossier') : 'Validé';
        if ($etatDossier !== '' && $etatDossier !== null) {
            $query->where('etat_dossier', $etatDossier);
        } else {
            $query->where('etat_dossier', '!=', 'Recu');
        }

        return (string) $etatDossier;
    }

    /**
     * Export CSV — reprend exactement les mêmes filtres que la liste
     * (baseQuery + statut + tri), sans pagination : bouton "Exporter CSV"
     * au niveau du bandeau Filtres actifs de familles/index.blade.php,
     * demande du 13/08/2026. chunk(500) plutôt que get() pour ne pas
     * charger tous les dossiers en mémoire d'un coup ; s'appuie sur le tri
     * appliqué par appliquerTri() (toujours au moins orderBy('id') par
     * défaut) pour un LIMIT/OFFSET stable entre les lots.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->baseQuery($request);
        $this->appliquerFiltreStatut($query, $request);
        $this->appliquerTri($query, $request);
        $query->with('quartier.secteur.ville');

        $colonnes = [
            'id' => 'ID',
            'nom' => 'Nom',
            'prenom' => 'Prénom',
            'email' => 'Email',
            'telephone' => 'Téléphone',
            'telephone_bis' => 'Téléphone (bis)',
            'adresse_complete' => 'Adresse',
            'ville' => 'Ville',
            'quartier' => 'Quartier',
            'nombre_adulte' => 'Adultes',
            'nombre_enfant' => 'Enfants',
            'criticite' => 'Criticité',
            'etat_dossier' => 'Statut',
            'zakat_el_fitr' => 'Zakat El Fitr',
            'sadaqa' => 'Sadaqa',
            'se_deplace' => 'Se déplace',
            'est_hotel' => 'Hôtel',
            'etudiant' => 'Étudiant',
            'langue' => 'Langue',
            'created_at' => 'Créé le',
        ];

        $nomFichier = 'familles_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query, $colonnes) {
            $flux = fopen('php://output', 'w');
            // BOM UTF-8 — sans lui Excel réinterprète mal les accents à
            // l'ouverture directe d'un CSV UTF-8 (comportement connu du
            // logiciel, indépendant de l'encodage réel du fichier).
            fwrite($flux, "\xEF\xBB\xBF");
            fputcsv($flux, array_values($colonnes), ';');

            $query->chunk(500, function ($lot) use ($flux, $colonnes) {
                foreach ($lot as $famille) {
                    $ligne = [];
                    foreach (array_keys($colonnes) as $champ) {
                        $ligne[] = match ($champ) {
                            'adresse_complete' => $famille->adresse_complete,
                            'ville' => $famille->ville ?? '',
                            'quartier' => $famille->quartier->nom ?? '',
                            'zakat_el_fitr', 'sadaqa', 'se_deplace', 'est_hotel', 'etudiant' => $famille->{$champ} ? 'Oui' : 'Non',
                            'created_at' => $famille->created_at?->format('d/m/Y') ?? '',
                            default => (string) ($famille->{$champ} ?? ''),
                        };
                    }
                    fputcsv($flux, $ligne, ';');
                }
            });

            fclose($flux);
        }, $nomFichier, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Suggestions d'autocomplétion pour les champs Nom / Téléphone de
     * familles/index.blade.php — 8 résultats max, réponse JSON légère
     * (seulement les colonnes affichées dans la liste déroulante).
     */
    public function rechercheSuggestions(Request $request): JsonResponse
    {
        $champ = $request->input('champ') === 'telephone' ? 'telephone' : 'nom';
        $terme = trim((string) $request->input('q', ''));

        if (mb_strlen($terme) < 2) {
            return response()->json([]);
        }

        $resultats = ($champ === 'telephone'
            ? Famille::query()->rechercheTelephone($terme)
            : Famille::query()->rechercheNom($terme))
            ->orderBy('nom')
            ->limit(8)
            ->get(['id', 'nom', 'prenom', 'telephone', 'telephone_bis']);

        return response()->json($resultats->map(fn(Famille $famille) => [
            'id' => $famille->id,
            'label' => trim($famille->prenom . ' ' . $famille->nom),
            'sous_label' => $famille->telephone ?? $famille->telephone_bis ?? '',
            // Valeur posée dans le champ texte au clic — le nom complet pour
            // le champ Nom, le numéro effectivement trouvé pour le champ
            // Téléphone (peut être telephone_bis si c'est lui qui matche).
            'valeur' => $champ === 'telephone'
                ? ((str_contains((string) $famille->telephone, $terme) ? $famille->telephone : $famille->telephone_bis) ?? '')
                : trim($famille->prenom . ' ' . $famille->nom),
        ]));
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
            'etudiant' => $query->orderBy('etudiant', $direction),
            'langue' => $query->orderBy('langue', $direction),
            'type_piece_identite' => $query->orderBy('type_piece_identite', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
        };
    }

    // ── Panneau de détail (consommé en JSON par DetailPanel.vue) ─────────

    public function show(int $id): JsonResponse
    {
        $famille = Famille::with(['quartier.secteur.ville', 'documents', 'secteursActivite', 'organismesAide'])->findOrFail($id);
        $this->assertAccesFamille($famille);

        // quartier.boundary / quartier.secteur.ville.boundary sont des colonnes
        // geometry (WKB binaire) — jamais de l'UTF-8 valide. Sans ça,
        // response()->json() plante avec "Malformed UTF-8 characters" dès
        // qu'une famille a un quartier résolu (voir CHANGELOG). Fix propre
        // en amont dans amana_shared (Quartier/Ville::$hidden) ; ceci reste
        // en filet de sécurité local le temps que ce paquet soit mis à jour.
        $famille->quartier?->makeHidden('boundary');
        $famille->quartier?->secteur?->ville?->makeHidden('boundary');

        // Verrouillage d'édition (décision du 15/08/2026) — ouvrir le
        // Dossier Panel, c'est TOUJOURS dans l'intention de l'éditer (seul
        // point d'entrée de ce endpoint, voir DetailPanel.vue), donc c'est
        // ici qu'on prend le verrou. Choix assumé de le faire sur ce GET
        // plutôt que via un endpoint POST dédié : les deux actions (charger
        // les données, verrouiller) sont indissociables du point de vue du
        // panneau, un GET+POST séparés n'apporterait qu'une fenêtre de race
        // condition supplémentaire pour peu de bénéfice — même logique
        // "simple v1" que le reste du panneau.
        //
        // Un verrou détenu par UN AUTRE utilisateur et encore frais (moins
        // de VERROU_TTL_MINUTES) bloque l'ouverture ; un verrou détenu par
        // le même utilisateur (ex : rechargement de page) ou périmé (crash
        // navigateur précédent, cf. commentaire sur VERROU_TTL_MINUTES) est
        // traversé normalement.
        $utilisateur = auth()->user();
        $verrouExpireLe = now()->subMinutes(Famille::VERROU_TTL_MINUTES);
        $verrouActifParAutrui = $famille->locked_by
            && (int) $famille->locked_by !== (int) $utilisateur->id
            && $famille->locked_at
            && $famille->locked_at->greaterThan($verrouExpireLe);

        if ($verrouActifParAutrui) {
            $proprietaire = Personne::find($famille->locked_by);

            return response()->json([
                'error' => 'verrouille',
                'message' => $proprietaire
                    ? "Ce dossier est en cours de modification par {$proprietaire->nom_complet}."
                    : 'Ce dossier est en cours de modification par un autre utilisateur.',
                'locked_by_nom' => $proprietaire?->nom_complet,
                'locked_at' => $famille->locked_at,
                // Un admin peut forcer le déverrouillage depuis ce blocage
                // (décision du 15/08/2026 — voir forcerDeverrouillage() et
                // DetailPanel.vue) : filet de sécurité si le verrou
                // précédent n'a pas pu se relâcher normalement (crash
                // navigateur avant l'expiration du TTL) et que quelqu'un a
                // besoin d'accéder au dossier avant les VERROU_TTL_MINUTES.
                'peut_forcer' => $utilisateur->isAdmin(),
            ], 423);
        }

        // etat_dossier_avant_verrouillage déjà renseigné ⇒ le verrou existe
        // déjà (nous-même en train de recharger, ou reprise d'un verrou
        // périmé laissé par un autre utilisateur) : ne PAS écraser la
        // valeur déjà capturée, sous peine de perdre le véritable état
        // d'origine (qui serait alors 'En cours', la valeur déjà bascule,
        // au lieu du vrai statut d'avant édition).
        if ($famille->etat_dossier_avant_verrouillage === null) {
            $famille->etat_dossier_avant_verrouillage = $famille->etat_dossier;
            if ($famille->etat_dossier !== 'En cours') {
                $famille->etat_dossier = 'En cours';
            }
        }
        $famille->locked_by = $utilisateur->id;
        $famille->locked_at = now();
        // saveQuietly() + pas d'audit() : un verrouillage/bascule
        // automatique à l'ouverture n'est pas une décision métier prise
        // par le staff, ce n'est pas ce que audit_logs doit tracer — seul
        // l'enregistrement explicite (update() ci-dessous) doit y figurer.
        $famille->saveQuietly();

        // Le JSON renvoyé au panneau affiche le VRAI statut d'origine, pas
        // la bascule interne 'En cours' qui vient d'être persistée en base
        // (décision du 15/08/2026 — 'En cours' n'est plus un choix possible
        // du <select> de DetailPanel.vue, voir Famille::ETATS_SELECTIONNABLES ;
        // le montrer quand même casserait la présélection du menu et
        // risquerait, si le staff n'y touche pas, de faire retomber le
        // <select> HTML sur sa première option par défaut — perte de
        // statut silencieuse). Cette réaffectation ne touche que l'objet
        // en mémoire, pas la base : 'En cours' y reste bien stocké, c'est
        // justement ce qui alimente le filtre de la vue principale.
        //
        // Cas particulier 'Recu' : ce statut n'est lui non plus PAS dans
        // ETATS_SELECTIONNABLES (jamais choisi manuellement depuis ce
        // panneau, voir IntakeController::store) — l'afficher tel quel
        // provoquerait exactement le même problème de présélection
        // invalide. On retombe alors sur 'En attente', premier statut de
        // traitement réel, qui est de toute façon la suite logique
        // attendue pour un dossier tout juste reçu qu'un membre du staff
        // vient d'ouvrir. Si le staff ferme sans enregistrer
        // (deverrouiller()), le dossier retrouve bien 'Recu' — ce
        // fallback n'affecte que ce qui s'affiche dans le formulaire.
        $famille->etat_dossier = in_array($famille->etat_dossier_avant_verrouillage, Famille::ETATS_SELECTIONNABLES, true)
            ? $famille->etat_dossier_avant_verrouillage
            : 'En attente';

        return response()->json($famille);
    }

    /**
     * Relâche le verrou d'édition sans enregistrer — appelé quand le
     * Dossier Panel se ferme sans sauvegarde (bouton Fermer/Annuler,
     * Escape, clic hors du panneau, ou fermeture/rechargement de l'onglet
     * via navigator.sendBeacon — voir DetailPanel.vue). Restaure
     * etat_dossier à sa valeur d'avant ouverture SANS passer par la
     * validation habituelle de update() (aucune édition n'a été commise,
     * ce n'est pas un enregistrement) — donc, contrairement à update(),
     * peut restaurer 'Recu' sans souci (voir Famille::ETATS_MODIFIABLES).
     *
     * Ne relâche que le verrou détenu par L'UTILISATEUR COURANT — un appel
     * tardif (ex : sendBeacon envoyé juste avant qu'un autre utilisateur
     * n'ait déjà repris un verrou expiré) ne doit jamais libérer le verrou
     * de quelqu'un d'autre.
     */
    public function deverrouiller(int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);
        $utilisateur = auth()->user();

        if ((int) $famille->locked_by !== (int) $utilisateur->id) {
            return response()->json(['ok' => true, 'message' => 'Rien à faire.']);
        }

        if ($famille->etat_dossier_avant_verrouillage !== null) {
            $famille->etat_dossier = $famille->etat_dossier_avant_verrouillage;
        }
        $famille->etat_dossier_avant_verrouillage = null;
        $famille->locked_by = null;
        $famille->locked_at = null;
        $famille->saveQuietly();

        return response()->json(['ok' => true]);
    }

    /**
     * Déverrouillage FORCÉ — réservé admin (role:admin, voir routes/web.php)
     * — décision du 15/08/2026 : "easy out" si un verrou reste bloqué (ex:
     * navigateur planté avant le beforeunload/sendBeacon de
     * DetailPanel.vue) et que l'attente des VERROU_TTL_MINUTES n'est pas
     * acceptable. Contrairement à deverrouiller(), ne vérifie PAS que
     * l'appelant est le détenteur du verrou — c'est précisément le point :
     * un admin peut libérer le verrou de N'IMPORTE QUI.
     *
     * Journalisé via audit() (contrairement à show()/deverrouiller(), qui
     * s'en abstiennent délibérément pour un verrouillage/déverrouillage
     * normal) : forcer la main sur la session d'édition d'un collègue est
     * une action à tracer, celle-là.
     */
    public function forcerDeverrouillage(int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);

        if ($famille->locked_by === null) {
            return response()->json(['ok' => true, 'message' => 'Rien à faire.']);
        }

        $ancienDetenteur = Personne::find($famille->locked_by)?->nom_complet ?? "personne #{$famille->locked_by}";

        $avant = $famille->only(['etat_dossier', 'locked_by', 'locked_at', 'etat_dossier_avant_verrouillage']);

        if ($famille->etat_dossier_avant_verrouillage !== null) {
            $famille->etat_dossier = $famille->etat_dossier_avant_verrouillage;
        }
        $famille->etat_dossier_avant_verrouillage = null;
        $famille->locked_by = null;
        $famille->locked_at = null;
        $famille->saveQuietly();

        audit(
            'deverrouillage_force',
            'familles',
            $famille->id,
            $avant,
            ['message' => "Verrou de {$ancienDetenteur} forcé par " . auth()->user()->nom_complet]
        );

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);
        $this->assertAccesFamille($famille);

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
            // 'boolean' accepte l'absence de clé comme false — cohérent
            // avec zakat_el_fitr/sadaqa/se_deplace ci-dessus, mais
            // manquait jusqu'ici pour est_hotel malgré sa présence dans
            // Famille::$fillable (silencieusement rejeté par $request->
            // validate() faute de règle déclarée) — corrigé le 12/08/2026.
            'est_hotel' => ['boolean'],
            'etudiant' => ['boolean'],
            'circonstances' => ['nullable', 'string'],
            'ressentit' => ['nullable', 'string'],
            'specificites' => ['nullable', 'string'],
            'criticite' => ['required', 'integer', 'min:0', 'max:5'],
            'langue' => ['required', 'string', 'in:fr,ar,en'],
            'etat_dossier' => ['required', 'string', 'in:' . implode(',', Famille::ETATS_SELECTIONNABLES)],
            'commentaire_dossier' => ['nullable', 'string'],

            // ── Champs collectés à l'intake, jusqu'ici non éditables
            // depuis le dossier (ajoutés le 12/08/2026, mêmes règles que
            // IntakeController::store — mais tout 'nullable' ici : un
            // dossier existant peut avoir été créé avant l'ajout de ces
            // colonnes ou importé sans elles (décision 6.8), on ne va
            // pas bloquer l'édition d'un dossier ancien sur leur
            // absence). Pas de required_if hosted_by/work_days
            // volontairement : l'admin peut légitimement vouloir
            // enregistrer un dossier partiellement complété sans que
            // l'UI le bloque, contrairement au formulaire public.
            'type_hebergement' => ['nullable', 'string', 'in:' . implode(',', Famille::TYPES_HEBERGEMENT)],
            'hosted_by' => ['nullable', 'string', 'max:255'],
            'type_piece_identite' => ['nullable', 'string', 'in:' . implode(',', Famille::TYPES_PIECE_IDENTITE)],
            'type_activite' => ['nullable', 'string', 'in:' . implode(',', Famille::TYPES_ACTIVITE)],
            'work_days' => ['nullable', 'integer', 'min:0', 'max:4'],
            'secteurs_activite' => ['nullable', 'array'],
            'secteurs_activite.*' => ['integer', 'exists:secteurs_activite,id'],
            'secteur_activite_autre' => ['nullable', 'string', 'max:150'],
            'organismes_aide' => ['nullable', 'array'],
            'organismes_aide.*' => ['integer', 'exists:organismes_aide,id'],
            'organisme_aide_autre' => ['nullable', 'string', 'max:150'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'criticite.max' => 'La criticité doit être comprise entre 0 et 5.',
            'etat_dossier.in' => 'Statut de dossier invalide.',
        ]);

        // Les pivots secteurs_activite/organismes_aide ne sont pas des
        // colonnes de familles — sync()és séparément plus bas, retirés ici
        // pour ne pas polluer $famille->fill() (Famille::$fillable ne les
        // contient de toute façon pas, mais autant être explicite).
        $secteursActivite = array_map('intval', $validated['secteurs_activite'] ?? []);
        $organismesAide = array_map('intval', $validated['organismes_aide'] ?? []);
        unset($validated['secteurs_activite'], $validated['organismes_aide']);

        $avant = $famille->toArray();
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
        // Fin de l'édition ⇒ verrou relâché, quel que soit qui le
        // détenait (un enregistrement réussi ferme la session d'édition
        // dans tous les cas — voir show()/deverrouiller()).
        $famille->etat_dossier_avant_verrouillage = null;
        $famille->locked_by = null;
        $famille->locked_at = null;
        $famille->save();

        // sync() plutôt qu'attach() : remplace intégralement la sélection
        // (une case décochée dans le panneau doit bien se traduire par une
        // suppression du pivot, pas juste par l'absence d'ajout) — même
        // sémantique que la création initiale via IntakeAttenteService.
        $famille->secteursActivite()->sync($secteursActivite);
        $famille->organismesAide()->sync($organismesAide);

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

        // Synchronisation contact Google (décision du 15/08/2026, élargit
        // la décision du 14/08/2026) — désormais déclenchée à CHAQUE
        // sauvegarde d'un dossier dont l'état FINAL (après cette
        // sauvegarde) est 'Validé', 'Rejeté' ou 'Archivé', pas seulement
        // au moment où il y entre. Sans cette condition, éditer le
        // téléphone ou le nom d'un dossier déjà validé ne mettait jamais à
        // jour le contact Google correspondant tant que le statut
        // lui-même ne changeait pas — contre-intuitif ("j'ai modifié le
        // dossier, pourquoi le contact ne bouge pas ?"). On garde quand
        // même une restriction sur les états 'Recu'/'En cours'/'En
        // attente' : un dossier encore en instruction n'a pas vocation à
        // pousser des changements vers Google Contacts à chaque frappe.
        // Depuis le 17/07/2026, intégration directe People API
        // (SynchroniserContactGoogle) au lieu du webhook Make.com — le job
        // détermine lui-même s'il doit créer ou mettre à jour le contact
        // via google_resource_name.
        $etatsDeclenchantSyncGoogle = ['Validé', 'Rejeté', 'Archivé'];
        if (in_array($famille->etat_dossier, $etatsDeclenchantSyncGoogle, true)) {
            \App\Jobs\SynchroniserContactGoogle::dispatch($famille->id);
        }

        return response()->json($famille->fresh(['quartier.secteur.ville', 'documents', 'secteursActivite', 'organismesAide']));
    }

    // ── Documents (consultation/upload — décision 6.4, stockage disque local) ──

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $famille = Famille::findOrFail($id);
        $this->assertAccesFamille($famille);

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
        $this->assertAccesFamille(Famille::findOrFail($id));

        if (!Storage::disk('local')->exists($document->disk_path)) {
            abort(404, 'Fichier introuvable sur le disque.');
        }

        return Storage::disk('local')->download($document->disk_path, $document->original_name);
    }

    public function destroyDocument(int $id, int $documentId): JsonResponse
    {
        $document = FamilleDocument::where('id_famille', $id)->findOrFail($documentId);
        $this->assertAccesFamille(Famille::findOrFail($id));
        $avant = $document->toArray();

        Storage::disk('local')->delete($document->disk_path);
        $document->delete();

        audit('delete', 'familles_documents', $documentId, $avant, null);

        return response()->json(['deleted' => true]);
    }
}

