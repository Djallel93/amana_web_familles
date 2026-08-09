<?php
// app/Console/Commands/ReparerEncodageFamilles.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Famille;
use Illuminate\Console\Command;

/**
 * Répare les familles dont un ou plusieurs champs texte contiennent des
 * octets qui ne sont pas de l'UTF-8 valide — conséquence d'un import CSV
 * fait avant la normalisation d'encodage ajoutée à FamilleCsvParser (export
 * Excel FR "CSV (séparateur point-virgule)", en Windows-1252 par défaut,
 * pas en UTF-8).
 *
 * Ces enregistrements se chargent normalement en base (MySQL n'a pas
 * rejeté l'écriture) mais font planter FamillesController::show() dès que
 * Laravel tente de les ré-encoder en JSON — "Malformed UTF-8 characters".
 *
 * Ré-interprète chaque champ invalide comme du Windows-1252 (l'hypothèse la
 * plus probable pour un export Excel FR) et le reconvertit en UTF-8 —
 * récupère le texte d'origine exactement, aucune perte de caractères.
 *
 * Par défaut : mode lecture seule (liste les familles/champs concernés
 * sans rien modifier). --appliquer pour écrire réellement les corrections.
 */
class ReparerEncodageFamilles extends Command
{
    protected $signature = 'amana:reparer-encodage-familles
                            {--appliquer : Écrit réellement les corrections (sinon, aperçu seul)}
                            {--diagnostiquer= : ID de famille — affiche les octets bruts et plusieurs décodages candidats sans rien écrire}';

    protected $description = "Détecte et corrige les champs texte en encodage invalide (import CSV non-UTF-8) sur la table familles";

    private const CHAMPS_TEXTE = [
        'nom', 'prenom', 'email', 'telephone', 'telephone_bis',
        'adresse', 'code_postal', 'ville_texte',
        'circonstances', 'ressentit', 'specificites', 'langue',
        'etat_dossier', 'commentaire_dossier',
        'hosted_by', 'work_days', 'work_sector', 'other_aid',
    ];

    /**
     * Encodages candidats testés en mode --diagnostiquer, si l'hypothèse
     * Windows-1252 par défaut ne correspond visiblement pas au fichier
     * source réel.
     */
    private const ENCODAGES_CANDIDATS = ['Windows-1252', 'ISO-8859-1', 'ISO-8859-15', 'UTF-16LE', 'UTF-16BE', 'CP850'];

    public function handle(): int
    {
        if ($idDiagnostic = $this->option('diagnostiquer')) {
            return $this->diagnostiquer((int) $idDiagnostic);
        }

        $appliquer = (bool) $this->option('appliquer');
        $nombreReparees = 0;

        Famille::query()->orderBy('id')->chunkById(100, function ($familles) use ($appliquer, &$nombreReparees) {
            foreach ($familles as $famille) {
                $corrections = [];

                foreach (self::CHAMPS_TEXTE as $champ) {
                    $valeur = $famille->getRawOriginal($champ);

                    if (!is_string($valeur) || $valeur === '' || mb_check_encoding($valeur, 'UTF-8')) {
                        continue;
                    }

                    $corrections[$champ] = mb_convert_encoding($valeur, 'UTF-8', 'Windows-1252');
                }

                if (empty($corrections)) {
                    continue;
                }

                $nombreReparees++;
                $this->line("Famille #{$famille->id} — champs concernés : " . implode(', ', array_keys($corrections)));

                if ($appliquer) {
                    $famille->forceFill($corrections)->saveQuietly();
                }
            }
        });

        if ($nombreReparees === 0) {
            $this->info('Aucune famille avec un encodage invalide détectée.');
            return self::SUCCESS;
        }

        if ($appliquer) {
            $this->info("{$nombreReparees} famille(s) corrigée(s).");
        } else {
            $this->warn("{$nombreReparees} famille(s) concernée(s) — aperçu seul, relancer avec --appliquer pour corriger.");
        }

        return self::SUCCESS;
    }

    /**
     * Reproduit exactement FamillesController::show() (mêmes relations
     * eager-loadées) et parcourt récursivement le tableau résultant —
     * l'erreur "Malformed UTF-8" pointe toujours vers le modèle Famille
     * (celui sur lequel toJson() est appelé), même quand l'octet invalide
     * est en réalité dans une relation imbriquée (document, quartier...).
     * Un scan limité aux colonnes de familles peut donc passer "propre" à
     * tort — d'où ce mode qui inspecte tout ce qui part réellement en JSON.
     */
    private function diagnostiquer(int $id): int
    {
        $famille = Famille::with(['quartier.secteur.ville', 'documents'])->find($id);

        if (!$famille) {
            $this->error("Famille #{$id} introuvable.");
            return self::FAILURE;
        }

        $trouve = false;
        $this->scannerRecursif($famille->toArray(), (string) $id, $trouve);

        if (!$trouve) {
            $this->info("Famille #{$id} (avec quartier/secteur/ville/documents) : aucun champ en encodage invalide.");
            $this->line('Le problème est donc ailleurs que dans les données elles-mêmes — voir la commande `file -i` sur le CSV source, ou une éventuelle corruption au niveau connexion/driver.');
        }

        return self::SUCCESS;
    }

    private function scannerRecursif(mixed $valeur, string $chemin, bool &$trouve): void
    {
        if (is_array($valeur)) {
            foreach ($valeur as $cle => $sousValeur) {
                $this->scannerRecursif($sousValeur, "{$chemin}.{$cle}", $trouve);
            }
            return;
        }

        if (!is_string($valeur) || $valeur === '' || mb_check_encoding($valeur, 'UTF-8')) {
            return;
        }

        $trouve = true;
        $this->line('');
        $this->line("<fg=yellow>{$chemin}</> (" . strlen($valeur) . ' octets)');

        $hex = implode(' ', array_map(fn($o) => str_pad(dechex(ord($o)), 2, '0', STR_PAD_LEFT), str_split($valeur)));
        $this->line("  Octets bruts : {$hex}");

        foreach (self::ENCODAGES_CANDIDATS as $encodage) {
            $essai = @mb_convert_encoding($valeur, 'UTF-8', $encodage);
            $valide = $essai !== false && mb_check_encoding($essai, 'UTF-8');
            $affichage = $valide ? $essai : '(conversion invalide)';
            $this->line("  {$encodage} → {$affichage}");
        }
    }
}
