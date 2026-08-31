<?php
// app/Services/IntakeAttenteService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Models\IntakeDemandeAttente;
use App\Support\TokenHasher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Centralise le cycle de vie d'une soumission du formulaire public d'intake
 * en attente de confirmation par email (ajout du 11/08/2026 — voir
 * migration create_intake_demandes_attente_table) :
 *
 *  1. creerDemande()   — IntakeController::store() : valide déjà fait en
 *     amont, ici on stocke les fichiers sur disque + la ligne en attente,
 *     et on écrase silencieusement toute demande non confirmée déjà en
 *     attente pour la même famille (même dédup qu'à la création réelle,
 *     voir trouverAttenteExistante()) — décision du 11/08/2026 : un
 *     deuxième essai avant confirmation remplace le premier plutôt que
 *     d'empiler deux liens de confirmation valides pour la même personne.
 *  2. confirmer()       — IntakeConfirmationController::confirmer() : ne
 *     s'exécute qu'au clic sur le lien reçu par email. Réutilise
 *     FamilleUpsertService::upsert() (même dédup contre les familles
 *     déjà existantes que l'ancien flux synchrone), déplace les fichiers
 *     du stockage temporaire vers leur emplacement définitif, puis
 *     nettoie la ligne + le dossier temporaire.
 *
 * Ne gère PAS l'envoi de l'email de confirmation ni la notification staff
 * — ça reste dans les contrôleurs, cette classe ne s'occupe que des
 * données/fichiers.
 *
 * Jeton haché à partir du 31/08/2026 (voir App\Support\TokenHasher et
 * migration 2026_08_31_000000_hash_existing_confirmation_tokens.php) :
 * creerDemande() renvoie désormais le jeton EN CLAIR séparément de la
 * ligne créée (dont `token` ne contient plus que le hash) — c'est ce
 * jeton en clair que le contrôleur appelant doit transmettre à la
 * Notification pour construire l'URL de confirmation, jamais
 * $demande->token. Conséquence sur le stockage des fichiers : le nom du
 * dossier temporaire ne peut plus être basé sur le jeton (devenu un hash
 * illisible et sans rapport avec le jeton en clair reçu plus tard via
 * l'URL) — basé sur l'id de la ligne à la place, ce qui impose de créer
 * la ligne AVANT de stocker les fichiers (voir ci-dessous), alors que
 * l'ordre inverse suffisait auparavant.
 */
class IntakeAttenteService
{
    private const DUREE_VALIDITE_HEURES = 48;

    public function __construct(
        private readonly FamilleUpsertService $upsertService,
    ) {
    }

    /**
     * @param array<string, mixed> $donneesValidees Mêmes clés que Famille::$fillable
     * @param int[] $secteursActivite
     * @param int[] $organismesAide
     * @param array<string, UploadedFile[]> $fichiersParSlot Clés attendues : identite, aide, resource
     * @return array{demande: IntakeDemandeAttente, token: string} `token` est le jeton EN CLAIR — à
     *         transmettre à la Notification, jamais lu depuis $demande->token (qui ne contient que le hash)
     */
    public function creerDemande(
        array $donneesValidees,
        array $secteursActivite,
        array $organismesAide,
        string $langue,
        array $fichiersParSlot,
    ): array {
        $existante = $this->trouverAttenteExistante($donneesValidees);
        if ($existante) {
            $this->supprimerDemande($existante);
        }

        $tokenEnClair = Str::random(60);

        // Ligne créée AVANT le stockage des fichiers (inversion par
        // rapport à l'ancien ordre) : le dossier de stockage temporaire
        // est désormais nommé d'après l'id de la ligne (voir
        // IntakeDemandeAttente::cheminStockageTemporaire()), qui n'existe
        // qu'une fois la ligne persistée. documents_meta démarre vide,
        // mis à jour juste après.
        $demande = IntakeDemandeAttente::create([
            'token' => TokenHasher::hash($tokenEnClair),
            'langue' => $langue,
            'donnees' => $donneesValidees,
            'secteurs_activite' => $secteursActivite,
            'organismes_aide' => $organismesAide,
            'documents_meta' => [],
            'expires_at' => now()->addHours(self::DUREE_VALIDITE_HEURES),
        ]);

        $documentsMeta = [];
        foreach ($fichiersParSlot as $slot => $fichiers) {
            $documentsMeta[$slot] = $this->stockerFichiersAttente($demande, $slot, $fichiers);
        }
        $demande->update(['documents_meta' => $documentsMeta]);

        return ['demande' => $demande, 'token' => $tokenEnClair];
    }

    /**
     * Même logique de rapprochement que FamilleUpsertService::trouverDoublon()
     * (priorité email, puis téléphone + nom), appliquée ici aux demandes
     * PAS ENCORE confirmées — une demande confirmée est supprimée par
     * confirmer() donc n'apparaît plus jamais dans cette table.
     */
    private function trouverAttenteExistante(array $donnees): ?IntakeDemandeAttente
    {
        if (!empty($donnees['email'])) {
            $demande = IntakeDemandeAttente::whereRaw(
                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.email"))) = ?',
                [strtolower($donnees['email'])],
            )->first();
            if ($demande) {
                return $demande;
            }
        }

        if (!empty($donnees['telephone']) && !empty($donnees['nom'])) {
            return IntakeDemandeAttente::whereRaw(
                'JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.telephone")) = ?',
                [$donnees['telephone']],
            )->whereRaw(
                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.nom"))) = ?',
                [strtolower($donnees['nom'])],
            )->first();
        }

        return null;
    }

    /**
     * @param UploadedFile[] $fichiers
     * @return array<int, array{disk_path: string, original_name: string, mime_type: string}>
     */
    private function stockerFichiersAttente(IntakeDemandeAttente $demande, string $slot, array $fichiers): array
    {
        $meta = [];

        foreach ($fichiers as $fichier) {
            if (!$fichier || !$fichier->isValid()) {
                continue;
            }

            $path = $fichier->store("{$demande->cheminStockageTemporaire()}/{$slot}", 'local');

            $meta[] = [
                'disk_path' => $path,
                'original_name' => $fichier->getClientOriginalName(),
                'mime_type' => $fichier->getClientMimeType(),
            ];
        }

        return $meta;
    }

    /**
     * Transforme une demande en attente confirmée en véritable dossier
     * Famille : dédup + upsert (FamilleUpsertService, même règle que
     * l'import en masse), déplacement des fichiers temporaires vers leur
     * emplacement définitif, puis nettoyage.
     *
     * Révision du 28/08/2026 (organisations partenaires) : si le dédup
     * trouve un dossier déjà rattaché à une AUTRE organisation que celle
     * choisie à l'étape "organisation" du formulaire, le dossier existant
     * n'est PAS modifié (voir FamilleUpsertService::upsert() —
     * rattachement_en_attente) — les fichiers déjà uploadés en attente
     * sont conservés tels quels côté disque, PAS déplacés/attachés au
     * dossier existant tant qu'un staff n'a pas validé le rattachement
     * (voir Admin\RattachementsController) ; ils restent simplement
     * orphelins dans le stockage temporaire jusqu'au nettoyage périodique,
     * même comportement qu'un lien de confirmation jamais cliqué.
     *
     * @return array{famille: Famille, cree: bool, rattachement_en_attente: bool}
     */
    public function confirmer(IntakeDemandeAttente $demande): array
    {
        $secteursActivite = $demande->secteurs_activite ?? [];
        $organismesAide = $demande->organismes_aide ?? [];

        $resultat = $this->upsertService->upsert(
            $demande->donnees,
            ['etat_dossier' => 'Recu', 'criticite' => 0],
            $secteursActivite,
            $organismesAide,
            'intake',
        );
        $famille = $resultat['famille'];

        if ($resultat['rattachement_en_attente']) {
            $this->supprimerDemande($demande);

            return ['famille' => $famille, 'cree' => false, 'rattachement_en_attente' => true];
        }

        $typeDocumentAide = ($demande->donnees['type_piece_identite'] ?? null) === 'autre' ? 'ame' : 'caf';

        $this->deplacerFichiers($demande, 'identite', $famille, 'identity');
        $this->deplacerFichiers($demande, 'aide', $famille, $typeDocumentAide);
        $this->deplacerFichiers($demande, 'resource', $famille, 'resource');

        $this->supprimerDemande($demande);

        return ['famille' => $famille, 'cree' => $resultat['cree'], 'rattachement_en_attente' => false];
    }

    private function deplacerFichiers(IntakeDemandeAttente $demande, string $slot, Famille $famille, string $typeDocument): void
    {
        $fichiers = $demande->documents_meta[$slot] ?? [];

        foreach ($fichiers as $fichier) {
            $ancienChemin = $fichier['disk_path'];
            if (!Storage::disk('local')->exists($ancienChemin)) {
                continue; // Fichier disparu entre-temps (purge manuelle, disque nettoyé…) : on n'échoue pas la confirmation pour ça.
            }

            $nouveauNom = basename($ancienChemin);
            $nouveauChemin = "familles/{$famille->id}/{$nouveauNom}";

            Storage::disk('local')->move($ancienChemin, $nouveauChemin);

            FamilleDocument::create([
                'id_famille' => $famille->id,
                'type' => $typeDocument,
                'disk_path' => $nouveauChemin,
                'original_name' => $fichier['original_name'],
                'mime_type' => $fichier['mime_type'],
                'uploaded_at' => now(),
            ]);
        }
    }

    /**
     * Supprime une demande en attente ainsi que ses fichiers temporaires —
     * appelé aussi bien en cas d'écrasement (nouvelle soumission avant
     * confirmation) qu'en fin de confirmer() (plus besoin de la garder une
     * fois le dossier créé).
     */
    public function supprimerDemande(IntakeDemandeAttente $demande): void
    {
        Storage::disk('local')->deleteDirectory($demande->cheminStockageTemporaire());
        $demande->delete();
    }
}
