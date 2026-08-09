<?php
// app/Support/FamilleCsvParser.php

declare(strict_types=1);

namespace App\Support;

/**
 * Parsing CSV partagé entre l'upload staff (ImportsController) et le
 * chargement du CSV réel en local (FamilleCsvSeeder) — même logique de
 * détection de séparateur / normalisation des booléens des deux côtés,
 * pour éviter une divergence entre les deux chemins d'import.
 *
 * Colonnes attendues (en-tête, insensible à la casse) : nom, prenom,
 * telephone, email, telephone_bis, adresse, code_postal, ville,
 * nombre_adulte, nombre_enfant, zakat_el_fitr, sadaqa, se_deplace,
 * criticite, langue, etat_dossier, commentaire_dossier.
 * "ville" (pas "ville_texte") côté CSV pour rester lisible côté staff ;
 * mappé vers ville_texte en interne.
 *
 * Le fichier est systématiquement normalisé en UTF-8 avant parsing (voir
 * versUtf8()) : un export Excel FR "CSV (séparateur point-virgule)" est en
 * Windows-1252 par défaut, pas en UTF-8. Sans cette étape, les octets bruts
 * finissent stockés tels quels en base (colonnes utf8mb4) — MySQL ne les
 * rejette pas systématiquement à l'écriture, mais le champ redevient
 * illisible dès que l'app tente de le ré-encoder en JSON (voir
 * FamillesController::show()), avec une erreur uniquement visible dans les
 * logs bien après l'import.
 */
class FamilleCsvParser
{
    public static function parse(string $path): array
    {
        $contenu = file_get_contents($path);
        if ($contenu === false) {
            return [];
        }

        $contenu = self::versUtf8($contenu);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contenu);
        rewind($handle);

        $entetes = fgetcsv($handle, 0, ';') ?: fgetcsv($handle, 0, ',');
        if (!$entetes) {
            fclose($handle);
            return [];
        }
        // Détection automatique du séparateur (';' usage courant Excel FR, ',' sinon).
        $separateur = count($entetes) > 1 ? ';' : ',';
        if ($separateur === ',') {
            rewind($handle);
            $entetes = fgetcsv($handle, 0, ',');
        }

        $entetes = array_map(fn($e) => strtolower(trim((string) $e)), $entetes);
        $mapVille = array_search('ville', $entetes, true);
        if ($mapVille !== false) {
            $entetes[$mapVille] = 'ville_texte';
        }

        $champsBooleens = ['zakat_el_fitr', 'sadaqa', 'se_deplace'];
        $lignes = [];

        while (($valeurs = fgetcsv($handle, 0, $separateur)) !== false) {
            if (count($valeurs) === 1 && trim((string) $valeurs[0]) === '') {
                continue; // ligne vide
            }
            $ligne = [];
            foreach ($entetes as $i => $cle) {
                $valeur = trim((string) ($valeurs[$i] ?? ''));
                if (in_array($cle, $champsBooleens, true)) {
                    $valeur = in_array(strtolower($valeur), ['1', 'oui', 'yes', 'true', 'vrai', 'x'], true) ? '1' : '0';
                }
                $ligne[$cle] = $valeur;
            }
            $lignes[] = $ligne;
        }

        fclose($handle);
        return $lignes;
    }

    /**
     * Normalise un contenu de fichier en UTF-8 valide :
     *   1. Retire le BOM UTF-8 éventuel (export Excel "CSV UTF-8").
     *   2. Si déjà de l'UTF-8 valide, ne touche à rien.
     *   3. Sinon, suppose Windows-1252 (export Excel FR classique — très
     *      proche de l'ISO-8859-1 mais avec guillemets/tirets typographiques
     *      en plus, donc préféré comme hypothèse par défaut) et convertit.
     */
    private static function versUtf8(string $contenu): string
    {
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }

        if (mb_check_encoding($contenu, 'UTF-8')) {
            return $contenu;
        }

        $encodage = mb_detect_encoding($contenu, ['Windows-1252', 'ISO-8859-1', 'UTF-8'], true) ?: 'Windows-1252';

        return mb_convert_encoding($contenu, 'UTF-8', $encodage);
    }
}
