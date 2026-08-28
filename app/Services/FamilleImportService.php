<?php
// app/Services/FamilleImportService.php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ResoudreAdresseFamille;
use Illuminate\Support\Facades\Validator;

/**
 * Traite UNE ligne d'import (saisie manuelle ou ligne CSV) — pipeline
 * commun aux deux sources, comme demandé à la décision 6.9 : "Les deux
 * doivent alimenter le même pipeline de traitement par ligne (...), pas
 * deux logiques séparées."
 *
 * Contrairement au formulaire public d'intake (IntakeController), cette
 * voie est staff-only : pas de documents obligatoires, pas de consentement
 * RGPD à cocher, et etat_dossier/criticite peuvent être fournis directement
 * dans la ligne (import de dossiers déjà triés).
 *
 * Révision du 28/08/2026 (organisations partenaires) : $idOrganisation est
 * désormais fourni par l'appelant (Admin\ImportsController) plutôt que par
 * la ligne elle-même — pour un gestionnaire_externe, TOUTE ligne importée
 * est forcée sur son organisation (voir ImportsController::resoudreIdOrganisation()),
 * la ligne CSV/manuelle ne peut pas s'attribuer une autre organisation.
 */
class FamilleImportService
{
    public function __construct(
        private readonly FamilleUpsertService $upsertService,
    ) {
    }

    /**
     * @param int|null $idOrganisation Organisation au nom de laquelle cette ligne est importée — voir FamilleUpsertService::upsert().
     * @param int|null $submittedBy ID de ref_personnes de l'auteur de l'import (audit de la demande de rattachement le cas échéant).
     * @return array{status: string, error_message: ?string, id_famille: ?int, cree: ?bool, donnees_avant: ?array}
     *         status ∈ pending|success|error|skipped|en_attente_rattachement (voir famille_import_rows)
     *         cree/donnees_avant ne sont renseignés que si status = success
     *         (voir FamilleUpsertService::upsert() — permettent le rollback).
     */
    public function traiterLigne(array $payload, ?int $idOrganisation = null, ?int $submittedBy = null): array
    {
        // Ligne entièrement vide (ex : ligne blanche en fin de CSV) → ignorée.
        if (empty(array_filter($payload, fn($v) => $v !== null && $v !== ''))) {
            return ['status' => 'skipped', 'error_message' => 'Ligne vide', 'id_famille' => null, 'cree' => null, 'donnees_avant' => null];
        }

        // Filet de sécurité : FamilleCsvParser normalise déjà l'encodage en
        // amont, mais on refuse ici toute valeur qui ne serait malgré tout
        // pas de l'UTF-8 valide plutôt que de la laisser atteindre la base —
        // une chaîne invalide stockée en colonne utf8mb4 casse le JSON
        // renvoyé par FamillesController::show() bien plus tard, sans lien
        // évident avec l'import qui en est la cause (voir CHANGELOG).
        foreach ($payload as $champ => $valeur) {
            if (is_string($valeur) && !mb_check_encoding($valeur, 'UTF-8')) {
                return [
                    'status' => 'error',
                    'error_message' => "Encodage invalide sur le champ « {$champ} » (fichier probablement pas en UTF-8).",
                    'id_famille' => null,
                    'cree' => null,
                    'donnees_avant' => null,
                ];
            }
        }

        $validator = Validator::make($payload, [
            'nom' => ['required', 'string', 'max:150'],
            'prenom' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'telephone_bis' => ['nullable', 'string', 'max:30'],
            'zakat_el_fitr' => ['nullable', 'boolean'],
            'sadaqa' => ['nullable', 'boolean'],
            'nombre_adulte' => ['nullable', 'integer', 'min:0', 'max:255'],
            'nombre_enfant' => ['nullable', 'integer', 'min:0', 'max:255'],
            'adresse' => ['nullable', 'string'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville_texte' => ['nullable', 'string', 'max:150'],
            'se_deplace' => ['nullable', 'boolean'],
            'criticite' => ['nullable', 'integer', 'min:0', 'max:5'],
            'langue' => ['nullable', 'string', 'in:fr,ar,en'],
            'etat_dossier' => ['nullable', 'string', 'in:Recu,En cours,En attente,Validé,Rejeté,Archivé'],
            'commentaire_dossier' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return [
                'status' => 'error',
                'error_message' => $validator->errors()->first(),
                'id_famille' => null,
                'cree' => null,
                'donnees_avant' => null,
            ];
        }

        $donnees = array_filter($validator->validated(), fn($v) => $v !== null && $v !== '');

        if ($idOrganisation) {
            $donnees['id_organisation'] = $idOrganisation;
        }

        try {
            $resultat = $this->upsertService->upsert($donnees, [
                'etat_dossier' => $donnees['etat_dossier'] ?? 'En cours',
                'criticite' => $donnees['criticite'] ?? 0,
            ], null, null, 'import', $submittedBy);

            if ($resultat['rattachement_en_attente']) {
                return [
                    'status' => 'en_attente_rattachement',
                    'error_message' => null,
                    'id_famille' => $resultat['famille']->id,
                    'cree' => false,
                    'donnees_avant' => null,
                ];
            }

            // Résolution géographique uniquement si une adresse a été fournie
            // (import "léger" possible sans adresse — ex : juste nom/téléphone).
            if (!empty($donnees['adresse'])) {
                ResoudreAdresseFamille::dispatch($resultat['famille']->id);
            }

            return [
                'status' => 'success',
                'error_message' => null,
                'id_famille' => $resultat['famille']->id,
                'cree' => $resultat['cree'],
                'donnees_avant' => $resultat['avant'],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error_message' => $e->getMessage(), 'id_famille' => null, 'cree' => null, 'donnees_avant' => null];
        }
    }
}
