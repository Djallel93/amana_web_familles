<?php
// app/Support/FamilleIntakeRules.php

declare(strict_types=1);

namespace App\Support;

use Amana\Shared\Services\PersonneIntakeService;
use App\Models\Famille;
use App\Models\FamilleDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Règles de validation et normalisation du formulaire de dossier famille —
 * extraites d'IntakeController::store() le 03/10/2026 pour être partagées
 * avec la création par le staff (FamilleCreationController) : les deux
 * formulaires (IntakeForm.vue en mode public / en mode staff) envoient les
 * mêmes champs, une seule définition évite qu'ils divergent.
 *
 * Seule différence : le consentement RGPD ('consentement') n'est exigé que
 * du formulaire public — le staff saisit pour le compte de la famille, voir
 * rules($avecConsentement).
 */
final class FamilleIntakeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $avecConsentement): array
    {
        // Bloc identité (nom/prenom/email/telephone/langue) : règles
        // communes centralisées dans amana/shared (extrait le 24/08/2026,
        // voir PersonneIntakeService) — pas d'unicité email ici, les
        // familles bénéficiaires n'ont pas de compte ref_personnes.
        // 'nom'/'prenom' passent ensuite de max:100 (défaut du service,
        // pensé pour un compte) à max:150 via l'override juste après : la
        // famille peut saisir un nom composé plus long qu'un nom de compte.
        $rules = array_merge(
            PersonneIntakeService::validationRules(telephoneMax: 30),
            [
                'nom' => ['required', 'string', 'max:150'],
                'prenom' => ['required', 'string', 'max:150'],
                'telephone_bis' => ['nullable', 'string', 'max:30'],

                'nombre_adulte' => ['required', 'integer', 'min:0', 'max:255'],
                'nombre_enfant' => ['required', 'integer', 'min:0', 'max:255'],
                'etudiant' => ['boolean'],

                'adresse' => ['required', 'string'],
                'code_postal' => ['required', 'string', 'max:10'],
                'ville_texte' => ['required', 'string', 'max:150'],
                'est_hotel' => ['boolean'],

                'circonstances' => ['required', 'string'],

                // ── Organisation ───────────────────────────────────────────
                // Ajouté le 28/08/2026 — liste fermée, obligatoire : chaque
                // dossier doit être rattaché à une organisation dès sa
                // création.
                'id_organisation' => ['required', 'integer', 'exists:organisations,id'],

                // ── Hébergement ────────────────────────────────────────────
                'type_hebergement' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_HEBERGEMENT)],
                'hosted_by' => ['nullable', 'string', 'max:255', 'required_if:type_hebergement,organisation'],

                // ── Situation administrative ──────────────────────────────
                'type_piece_identite' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_PIECE_IDENTITE)],
                // labels_* (01/10/2026) : libellé optionnel par fichier,
                // tableau PARALLÈLE à documents_* (même index) — le fichier
                // prend ce nom, voir FamilleDocument::nomAvecLabel().
                'documents_identite' => ['required', 'array', 'min:1', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'documents_identite.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
                'labels_identite' => ['nullable', 'array', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'labels_identite.*' => ['nullable', 'string', 'max:100'],
                'documents_aide' => ['required', 'array', 'min:1', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'documents_aide.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
                'labels_aide' => ['nullable', 'array', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'labels_aide.*' => ['nullable', 'string', 'max:100'],

                // ── Activité professionnelle ───────────────────────────────
                'type_activite' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_ACTIVITE)],
                // Plafonné à 4 (temps partiel ≠ semaine complète) — demande
                // du 09/08/2026.
                'work_days' => ['nullable', 'integer', 'min:0', 'max:4', 'required_if:type_activite,temps_partiel'],
                // Pas de required_unless ici : « au moins un secteur COCHÉ »
                // est trop strict si secteur_activite_autre est rempli — la
                // combinaison des deux est validée dans
                // ajouterValidationSecteurs().
                'secteurs_activite' => ['nullable', 'array'],
                'secteurs_activite.*' => ['integer', 'exists:secteurs_activite,id'],
                'secteur_activite_autre' => ['nullable', 'string', 'max:150'],

                // ── Ressources ──────────────────────────────────────────────
                // 'nullable' : « aucune aide perçue » est une réponse valide
                // (correction du 09/08/2026).
                'organismes_aide' => ['nullable', 'array'],
                'organismes_aide.*' => ['integer', 'exists:organismes_aide,id'],
                'organisme_aide_autre' => ['nullable', 'string', 'max:150'],
                'documents_resource' => ['nullable', 'array', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'documents_resource.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
                'labels_resource' => ['nullable', 'array', 'max:' . FamilleDocument::MAX_PAR_TYPE],
                'labels_resource.*' => ['nullable', 'string', 'max:100'],
            ],
        );

        if ($avecConsentement) {
            $rules['consentement'] = ['required', 'accepted'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'telephone.regex' => 'Numéro de téléphone invalide.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => 'Format d\'email invalide.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'ville_texte.required' => 'La ville est obligatoire.',
            'circonstances.required' => 'Merci de décrire brièvement votre situation.',
            'hosted_by.required_if' => 'Merci d\'indiquer le nom de l\'organisation.',
            'documents_identite.required' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.min' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.max' => 'Cinq justificatifs d\'identité maximum.',
            'documents_aide.max' => 'Cinq justificatifs maximum par section.',
            'documents_resource.max' => 'Cinq justificatifs maximum par section.',
            'labels_identite.*.max' => 'Le libellé ne doit pas dépasser 100 caractères.',
            'labels_aide.*.max' => 'Le libellé ne doit pas dépasser 100 caractères.',
            'labels_resource.*.max' => 'Le libellé ne doit pas dépasser 100 caractères.',
            'documents_identite.*.mimes' => 'Formats acceptés : PDF, JPG, PNG, DOC, DOCX.',
            'documents_aide.required' => 'Ce justificatif est obligatoire.',
            'documents_aide.min' => 'Ce justificatif est obligatoire.',
            'work_days.required_if' => 'Merci d\'indiquer le nombre de jours travaillés.',
            'consentement.required' => 'Vous devez accepter le traitement de vos données pour continuer.',
            'consentement.accepted' => 'Vous devez accepter le traitement de vos données pour continuer.',
        ];
    }

    /**
     * « Au moins un secteur, ou "autre" précisé » dès qu'il y a une activité
     * (temps plein ou partiel) — cohérent avec la validation côté Vue.
     */
    public static function ajouterValidationSecteurs(Validator $validator, Request $request): void
    {
        $validator->after(function ($validator) use ($request) {
            $typeActivite = $request->input('type_activite');
            $aSecteur = !empty($request->input('secteurs_activite', []))
                || filled($request->input('secteur_activite_autre'));

            if (in_array($typeActivite, ['temps_plein', 'temps_partiel'], true) && !$aSecteur) {
                $validator->errors()->add('secteurs_activite', 'Merci de sélectionner au moins un secteur, ou de préciser "autre".');
            }
        });
    }

    /**
     * Sépare les données validées en champs Famille + listes de secteurs /
     * organismes (ids), et applique la cohérence du branchement du
     * formulaire (« Non » = pas de secteur ni de jours ; hébergement hors
     * organisation = pas de « hébergé par »), même si le client a envoyé des
     * valeurs en trop.
     *
     * @param array<string, mixed> $valide Résultat de $validator->validated()
     * @return array{donnees: array<string, mixed>, secteurs: int[], organismes: int[]}
     */
    public static function normaliser(array $valide): array
    {
        $secteursActivite = array_map('intval', $valide['secteurs_activite'] ?? []);
        $organismesAide = array_map('intval', $valide['organismes_aide'] ?? []);
        $valide['id_organisation'] = (int) $valide['id_organisation'];

        unset(
            $valide['consentement'],
            $valide['documents_identite'],
            $valide['documents_aide'],
            $valide['documents_resource'],
            $valide['labels_identite'],
            $valide['labels_aide'],
            $valide['labels_resource'],
            $valide['secteurs_activite'],
            $valide['organismes_aide'],
        );

        if ($valide['type_activite'] === 'non') {
            $valide['work_days'] = null;
            $valide['secteur_activite_autre'] = null;
            $secteursActivite = [];
        } elseif ($valide['type_activite'] !== 'temps_partiel') {
            $valide['work_days'] = null;
        }

        if ($valide['type_hebergement'] !== 'organisation') {
            $valide['hosted_by'] = null;
        }

        return ['donnees' => $valide, 'secteurs' => $secteursActivite, 'organismes' => $organismesAide];
    }
}
