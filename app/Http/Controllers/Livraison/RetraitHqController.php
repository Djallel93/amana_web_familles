<?php
// app/Http/Controllers/Livraison/RetraitHqController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
use App\Http\Controllers\Livraison\Concerns\UrlRetourEquipe;
use App\Models\Campagne;
use App\Models\Livraison;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Écran "Retrait QG" — familles se_deplace (24/09/2026, prompt de cette
 * date §2). Pendant de ChargementController, MÊME équipe (equipe_chargement,
 * PAS un nouveau rôle — prompt §Additional points 3 : "I insist that
 * there is NO NEW TEAM ROLE created in this change [...] usually when all
 * drivers are loaded and start delivery families come and get their
 * packages right after so the same team handles both").
 *
 * Structurellement différent de ChargementController malgré tout : ici on
 * liste des Livraison directement (jamais de RouteLivraison — une famille
 * se_deplace est exclue du clustering, voir Livraison::
 * scopeSeDeplaceEffectif() / RouteGenerationService), triées par
 * heure_arrivee_prevue_hq (l'ordre de passage prévu, voir
 * RetraitHqSchedulingService) plutôt que par urgence de créneau.
 *
 * 4 statuts affichés (prompt §3), dérivés de 2 colonnes existantes plutôt
 * que d'un nouveau statut unique — délibéré, pour réutiliser exactement
 * le même statut_conditionnement que Packaging/Chargement (§1.2 : "en
 * preparation"/"Prête" sont VRAIMENT le même statut_conditionnement que
 * partout ailleurs dans le domaine livraison, pas une resaisie) :
 *   - statut_conditionnement != 'prete'            → "En préparation"
 *   - statut_conditionnement == 'prete' && statut_retrait_hq == null → "Prête"   (boutons actifs)
 *   - statut_retrait_hq == 'delivre'                → "Livré"
 *   - statut_retrait_hq == 'non_delivre'             → "Non livré" (no-show)
 */
class RetraitHqController extends Controller
{
    use FiltreCampagnesEquipe;
    use UrlRetourEquipe;

    /**
     * Point d'entrée sans campagne — même raisonnement que
     * ChargementController::choisir() (§4.1 du 05/09/2026), même équipe.
     */
    public function choisir(): View
    {
        $campagnes = $this->campagnesPourEquipe(Auth::user(), 'equipe_chargement', avecJournees: false);

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Retrait QG — choisir une campagne',
            'routeIndex' => 'livraison.retrait-hq.index',
            'avecJournee' => false,
        ]);
    }

    public function index(Request $request, Campagne $campagne): View
    {
        $filtre = $this->filtreDepuisRequete($request);
        ['lignes' => $lignes, 'stats' => $stats] = $this->construireListe($campagne, $filtre);

        return view('livraison.retrait-hq', [
            'campagne' => $campagne,
            'lignes' => $lignes,
            'stats' => $stats,
            'filtreRetraitHq' => $filtre,
            'urlRetour' => $this->urlRetourEquipe($campagne, 'livraison.retrait-hq.choisir'),
        ]);
    }

    /**
     * Polling (même cadence/mécanique que ChargementController::liste()).
     */
    public function liste(Request $request, Campagne $campagne): JsonResponse
    {
        $filtre = $this->filtreDepuisRequete($request);
        ['lignes' => $lignes, 'stats' => $stats] = $this->construireListe($campagne, $filtre);

        return response()->json(['stats' => $stats, 'lignes' => $lignes]);
    }

    private function filtreDepuisRequete(Request $request): string
    {
        $valeur = $request->input('filtre_retrait_hq', 'toutes');

        return is_string($valeur) ? $valeur : 'toutes';
    }

    /**
     * @return array{lignes: list<array{id: int, statut: string, sig: string, html: string}>, stats: array{total: int, en_preparation: int, pretes: int, delivrees: int, non_delivrees: int, avec_email: int}}
     */
    private function construireListe(Campagne $campagne, string $filtre): array
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->seDeplaceEffectif(true)
            ->with(['famille:id,nom,prenom,email,etudiant,est_hotel,nombre_enfant'])
            ->orderByRaw('heure_arrivee_prevue_hq IS NULL, heure_arrivee_prevue_hq ASC')
            ->get()
            ->map(function (Livraison $livraison) {
                $livraison->statutRetraitHqAffiche = $this->statutAffiche($livraison);

                return $livraison;
            });

        // Additional points 2 du prompt du 24/09/2026 : "add a with
        // emails stat card" — total plutôt que juste un %, cohérent avec
        // les autres cartes stat de cet écran.
        $stats = [
            'total' => $livraisons->count(),
            'en_preparation' => $livraisons->where('statutRetraitHqAffiche', 'en_preparation')->count(),
            'pretes' => $livraisons->where('statutRetraitHqAffiche', 'prete')->count(),
            'delivrees' => $livraisons->where('statutRetraitHqAffiche', 'delivre')->count(),
            'non_delivrees' => $livraisons->where('statutRetraitHqAffiche', 'non_delivre')->count(),
            'avec_email' => $livraisons->filter(fn (Livraison $l) => !empty($l->famille->email))->count(),
        ];

        if (in_array($filtre, self::STATUTS_AFFICHES, true)) {
            $livraisons = $livraisons->where('statutRetraitHqAffiche', $filtre)->values();
        }

        $lignes = $livraisons->map(function (Livraison $livraison) {
            $html = trim(view('livraison.partials.retrait-hq-ligne', ['livraison' => $livraison])->render());

            return [
                'id' => $livraison->id,
                'statut' => $livraison->statutRetraitHqAffiche,
                'sig' => md5($html),
                'html' => $html,
            ];
        })->all();

        return ['lignes' => $lignes, 'stats' => $stats];
    }

    private const STATUTS_AFFICHES = ['en_preparation', 'prete', 'delivre', 'non_delivre'];

    /**
     * Voir le docblock de classe pour le détail des 4 états — dérivés,
     * jamais stockés tels quels.
     */
    private function statutAffiche(Livraison $livraison): string
    {
        if ($livraison->statut_conditionnement !== 'prete') {
            return 'en_preparation';
        }

        return $livraison->statut_retrait_hq ?? 'prete';
    }

    /**
     * "Livré" — actif uniquement si la ligne est au statut affiché
     * "prete" (prompt §3 : "when package has status pret, Buttons become
     * clickable [...] confirm delivery or not"), revérifié ici
     * côté serveur (le bouton est déjà masqué/désactivé côté vue tant
     * que ce n'est pas le cas, mais un double-clic concurrent depuis 2
     * appareils reste possible).
     */
    public function marquerLivre(Livraison $livraison): JsonResponse
    {
        if ($this->statutAffiche($livraison) !== 'prete') {
            return response()->json(['success' => false, 'message' => 'Cette famille n\'est pas (ou plus) au statut "Prête".'], 422);
        }

        $livraison->update(['statut_retrait_hq' => 'delivre', 'statut' => 'livree']);

        return response()->json(['success' => true]);
    }

    /**
     * "Non livré" — famille non présentée (no-show), même garde-fou que
     * marquerLivre() ci-dessus. Ne touche PAS livraisons.statut (reste
     * 'assignee'/'non_assignee' selon le cas — une famille non venue n'a
     * pas reçu son colis, contrairement à 'livree').
     */
    public function marquerNonLivre(Livraison $livraison): JsonResponse
    {
        if ($this->statutAffiche($livraison) !== 'prete') {
            return response()->json(['success' => false, 'message' => 'Cette famille n\'est pas (ou plus) au statut "Prête".'], 422);
        }

        $livraison->update(['statut_retrait_hq' => 'non_delivre']);

        return response()->json(['success' => true]);
    }

    /**
     * Cible du QR envoyé par RetraitHqNotification — même principe que
     * MaRouteController::confirmerScan() (scan = confirmation directe,
     * pas un aller-retour "voir puis confirmer" séparé) : un scan sur une
     * ligne "Prête" la marque livrée immédiatement ; idempotent (rescanner
     * une ligne déjà "Livré" ne fait que la réafficher, voir le garde
     * statutAffiche() !== 'prete' ci-dessous — pas d'erreur pour autant,
     * l'équipe doit pouvoir rouvrir l'écran de confirmation sans risque).
     */
    public function scan(Livraison $livraison): View
    {
        if ($this->statutAffiche($livraison) === 'prete') {
            $livraison->update(['statut_retrait_hq' => 'delivre', 'statut' => 'livree']);
        }

        return view('livraison.retrait-hq-scan', [
            'livraison' => $livraison,
            'statutAffiche' => $this->statutAffiche($livraison),
        ]);
    }
}
