<?php
// app/Services/BenevoleProfileExtension.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Contracts\ProfileExtension;
use Amana\Shared\Models\Personne;
use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\VehiculeType;
use Illuminate\Support\Facades\DB;

/**
 * Section « Informations bénévole » de la page « Mon profil » (amana/shared).
 * Liée dans AppServiceProvider::register() ; route profile.extra.update dans
 * routes/familles.php.
 *
 * Existe seulement pour une personne qui A un BenevoleProfil (candidature
 * bénévole acceptée) : sinon fields() est vide et la page masque la section —
 * ce module ne crée jamais de profil bénévole.
 *
 * Modifiable par la personne elle-même : langue préférée, permis, type de
 * véhicule, secteurs couverts (mêmes champs que l'écran admin « Personnes »).
 * JAMAIS exposé ni écrit ici : `statut` (validation staff), rôles, quoi que ce
 * soit piloté par un administrateur.
 */
class BenevoleProfileExtension implements ProfileExtension
{
    public function title(): string
    {
        return 'Informations bénévole';
    }

    public function fields(Personne $personne): array
    {
        $profil = $personne->benevoleProfil;

        if (! $profil) {
            return [];
        }

        return [
            [
                'name' => 'langue_preferee',
                'label' => 'Langue préférée',
                'type' => 'select',
                'required' => true,
                'options' => ['fr' => 'Français', 'ar' => 'Arabe', 'en' => 'Anglais'],
                'value' => $profil->langue_preferee,
                'hint' => 'Langue utilisée pour vous écrire (ex. campagnes de disponibilités).',
            ],
            [
                'name' => 'permis',
                'label' => 'Je possède le permis de conduire',
                'type' => 'checkbox',
                'value' => (bool) $profil->permis,
            ],
            [
                'name' => 'id_vehicule_type',
                'label' => 'Véhicule',
                'type' => 'select',
                'required' => true,
                'options' => VehiculeType::orderBy('id')->pluck('type', 'id')->all(),
                'value' => $profil->id_vehicule_type,
            ],
            [
                'name' => 'secteurs',
                'label' => 'Secteurs que je peux couvrir',
                'type' => 'multiselect',
                'options' => Secteur::with('ville')->orderBy('nom')->get()
                    ->mapWithKeys(fn (Secteur $s) => [$s->id => ($s->ville?->nom ?? '?') . ' - ' . $s->nom])
                    ->sort()
                    ->all(),
                'value' => $profil->secteurs()->pluck('secteurs.id')->all(),
            ],
        ];
    }

    public function rules(Personne $personne): array
    {
        $commun = config('amana-shared.connection', 'commun');

        return [
            'langue_preferee' => ['required', 'in:fr,ar,en'],
            'permis' => ['boolean'],
            'id_vehicule_type' => ['required', 'integer', "exists:{$commun}.ref_vehicules,id"],
            'secteurs' => ['array'],
            'secteurs.*' => ['integer', "exists:{$commun}.secteurs,id"],
        ];
    }

    public function save(Personne $personne, array $validated): void
    {
        $profil = $personne->benevoleProfil;

        if (! $profil) {
            return;
        }

        DB::connection($profil->getConnectionName())->transaction(function () use ($profil, $validated): void {
            // Affectation champ par champ : `statut` (fillable dans le modèle) n'est jamais touché.
            $profil->langue_preferee = $validated['langue_preferee'];
            $profil->permis = filter_var($validated['permis'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $profil->id_vehicule_type = (int) $validated['id_vehicule_type'];
            $profil->save();
            $profil->secteurs()->sync($validated['secteurs'] ?? []);
        });
    }

    public function view(): ?string
    {
        return null;
    }
}
