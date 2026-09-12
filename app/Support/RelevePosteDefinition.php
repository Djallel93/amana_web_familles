<?php
// app/Support/RelevePosteDefinition.php

declare(strict_types=1);

namespace App\Support;

use App\Models\CampagneArrivee;
use App\Models\Donation;

/**
 * Paramétrage d'un "poste de relevé ponctuel" (pesée / réception) —
 * extrait le 10/09/2026 (Section A1 du refactor) : PeseeController et
 * ReceptionController (+ leurs vues pesee.blade.php/reception.blade.php)
 * étaient des quasi-duplicatas ne différant que par le modèle
 * (Donation/CampagneArrivee), le nom de champ (poids_kg/nombre_donateur)
 * et quelques libellés FR — voir PosteReleveController pour la logique
 * partagée que cet objet paramètre.
 *
 * `type` sert de clé partout où le nommage existant encodait déjà
 * pesée/réception : le nom du groupe de routes (livraison.{type}.*), le
 * préfixe d'URL (livraison/{type}), et — pour modifier()/supprimer() —
 * `segmentRessource` correspond au segment déjà utilisé dans ces routes
 * (dons/{don} vs arrivees/{arrivee}), lui-même identique à `cleListeJson`
 * (le payload journal() renvoyait déjà `dons`/`arrivees`). Ce n'est pas
 * une coïncidence à retenir comme telle : plutôt une conséquence du fait
 * que les deux postes suivaient déjà la même convention de nommage avant
 * ce refactor, d'où la possibilité de tout dériver de `type` + ce seul
 * objet plutôt que de multiplier les paramètres non corrélés.
 */
final readonly class RelevePosteDefinition
{
    public function __construct(
        public string $type,
        public string $modelClass,
        public string $champ,
        public string $unite,
        public string $titre,
        public string $titreChoix,
        public string $titreHistorique,
        public string $libelleColonne,
        public string $messageVide,
        public string $confirmationSuppression,
        public string $promptModification,
        public string $cleListeJson,
        public string $cleItemJson,
        public string $cleTotalJournalJson,
        public string $accesseurTotalCampagne,
        public string $suffixeTotal,
        public string $pas,
        public string $min,
        public string $max,
        public string $placeholder,
        public string $regleValidation,
        public bool $estEntier,
        public string $role,
    ) {
    }

    public static function pour(string $type): self
    {
        return match ($type) {
            'pesee' => self::pesee(),
            'reception' => self::reception(),
            default => throw new \InvalidArgumentException("Type de poste de relevé inconnu : {$type}"),
        };
    }

    public static function pesee(): self
    {
        return new self(
            type: 'pesee',
            modelClass: Donation::class,
            champ: 'poids_kg',
            unite: 'kg',
            titre: 'Pesée des dons',
            titreChoix: 'Pesée — choisir une campagne',
            titreHistorique: 'Historique des pesées',
            libelleColonne: 'Poids (kg)',
            messageVide: "Aucun relevé pour l'instant.",
            confirmationSuppression: 'Supprimer ce relevé ?',
            promptModification: 'Nouveau poids (kg) :',
            cleListeJson: 'dons',
            cleItemJson: 'don',
            cleTotalJournalJson: 'total_kg',
            accesseurTotalCampagne: 'poids_collecte_kg',
            suffixeTotal: 'kg',
            pas: '0.1',
            min: '0.1',
            max: '2000',
            placeholder: '0.0',
            regleValidation: 'required|numeric|min:0.1|max:2000',
            estEntier: false,
            role: 'equipe_pesee',
        );
    }

    public static function reception(): self
    {
        return new self(
            type: 'reception',
            modelClass: CampagneArrivee::class,
            champ: 'nombre_donateur',
            unite: 'donateurs',
            titre: 'Réception des donateurs',
            titreChoix: 'Réception — choisir une campagne',
            titreHistorique: 'Historique des arrivées',
            libelleColonne: 'Donateurs',
            messageVide: "Aucune arrivée pour l'instant.",
            confirmationSuppression: 'Supprimer cette arrivée ?',
            promptModification: 'Nouveau nombre de donateurs :',
            cleListeJson: 'arrivees',
            cleItemJson: 'arrivee',
            cleTotalJournalJson: 'total_donateurs',
            accesseurTotalCampagne: 'nombre_menages',
            suffixeTotal: 'donateurs',
            pas: '1',
            min: '1',
            max: '5000',
            placeholder: '0',
            regleValidation: 'required|integer|min:1|max:5000',
            estEntier: true,
            role: 'equipe_reception',
        );
    }
}
