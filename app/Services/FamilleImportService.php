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
 */
class FamilleImportService
{
    public function __construct(
        private readonly FamilleUpsertService $upsertService,
    ) {
    }

    /**
     * @return array{status: string, error_message: ?string, id_famille: ?int}
     *         status ∈ pending|success|error|skipped (voir famille_import_rows)
     */
    public function traiterLigne(array $payload): array
    {
        // Ligne entièrement vide (ex : ligne blanche en fin de CSV) → ignorée.
        if (empty(array_filter($payload, fn($v) => $v !== null && $v !== ''))) {
            return ['status' => 'skipped', 'error_message' => 'Ligne vide', 'id_famille' => null];
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
            ];
        }

        $donnees = array_filter($validator->validated(), fn($v) => $v !== null && $v !== '');

        try {
            $resultat = $this->upsertService->upsert($donnees, [
                'etat_dossier' => $donnees['etat_dossier'] ?? 'En cours',
                'criticite' => $donnees['criticite'] ?? 0,
            ]);

            // Résolution géographique uniquement si une adresse a été fournie
            // (import "léger" possible sans adresse — ex : juste nom/téléphone).
            if (!empty($donnees['adresse'])) {
                ResoudreAdresseFamille::dispatch($resultat['famille']->id);
            }

            return ['status' => 'success', 'error_message' => null, 'id_famille' => $resultat['famille']->id];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error_message' => $e->getMessage(), 'id_famille' => null];
        }
    }
}
