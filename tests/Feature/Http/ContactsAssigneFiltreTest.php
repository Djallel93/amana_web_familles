<?php
// tests/Feature/Http/ContactsAssigneFiltreTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Filtre « Assigné à » de la file de contact (01/10/2026) : une personne
 * précise, ou « Non assigné » — voir ContactTrackingController::queteBase()
 * et FamilleFilterPanel.vue (prop avecAssignation).
 */
class ContactsAssigneFiltreTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function creerCampagne(): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);
    }

    private function creerLivraison(Campagne $campagne, ?int $idAssignee): Livraison
    {
        return Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
            'id_personne_assignee' => $idAssignee,
        ]);
    }

    /** @return int[] */
    private function idsFile($reponse): array
    {
        return collect($reponse->json('data') ?? $reponse->json())->pluck('id')->sort()->values()->all();
    }

    public function test_filtre_par_personne_assignee_ne_renvoie_que_ses_livraisons(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $autre = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        $siennes = $this->creerLivraison($campagne, $gestionnaire->id);
        $this->creerLivraison($campagne, $autre->id);
        $this->creerLivraison($campagne, null);

        $reponse = $this->actingAs($gestionnaire)->getJson(route('livraison.contacts.queue', [
            'id_campagne' => $campagne->id,
            'id_personne_assignee' => $gestionnaire->id,
        ]))->assertOk();

        $this->assertSame([$siennes->id], $this->idsFile($reponse));
    }

    public function test_filtre_non_assigne_ne_renvoie_que_les_livraisons_sans_assignee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        $this->creerLivraison($campagne, $gestionnaire->id);
        $sansAssignee = $this->creerLivraison($campagne, null);

        $reponse = $this->actingAs($gestionnaire)->getJson(route('livraison.contacts.queue', [
            'id_campagne' => $campagne->id,
            'non_assigne' => 1,
        ]))->assertOk();

        $this->assertSame([$sansAssignee->id], $this->idsFile($reponse));
    }

    public function test_non_assigne_prime_sur_id_personne_assignee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        $this->creerLivraison($campagne, $gestionnaire->id);
        $sansAssignee = $this->creerLivraison($campagne, null);

        $reponse = $this->actingAs($gestionnaire)->getJson(route('livraison.contacts.queue', [
            'id_campagne' => $campagne->id,
            'id_personne_assignee' => $gestionnaire->id,
            'non_assigne' => 1,
        ]))->assertOk();

        $this->assertSame([$sansAssignee->id], $this->idsFile($reponse));
    }

    public function test_sans_filtre_assignation_toutes_les_livraisons_sont_renvoyees(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        $a = $this->creerLivraison($campagne, $gestionnaire->id);
        $b = $this->creerLivraison($campagne, null);

        $reponse = $this->actingAs($gestionnaire)->getJson(route('livraison.contacts.queue', [
            'id_campagne' => $campagne->id,
        ]))->assertOk();

        $this->assertSame([$a->id, $b->id], $this->idsFile($reponse));
    }
}
