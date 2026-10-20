<?php
// tests/Feature/Http/GuideTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Models\CampagneEquipeMembre;
use App\Support\Guide\GuideContenu;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Guide d'utilisation (18/10/2026) : chaque rôle n'accède qu'à son guide, plus les pages communes ; le filtrage se
 * fait côté serveur — le texte des autres rôles n'est jamais envoyé.
 */
class GuideTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    /** @return list<string> */
    private function idsRecus(Personne $personne): array
    {
        $ids = [];
        $this->actingAs($personne)
            ->get(route('guide.index'))
            ->assertOk()
            ->assertInertia(function ($page) use (&$ids) {
                $page->component('Guide/GuideIndex');
                $ids = collect($page->toArray()['props']['sections'])->pluck('id')->all();
            });

        return $ids;
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function personas(): array
    {
        return [
            'bénévole (chauffeur)' => [['benevole'], ['bienvenue', 'statistiques', 'ma-tournee']],
            'membre' => [['membre'], ['bienvenue', 'dossiers', 'statistiques', 'ma-tournee']],
            'équipe réception' => [['benevole', 'equipe_reception'], ['bienvenue', 'statistiques', 'ma-tournee', 'reception']],
            'équipe pesée' => [['benevole', 'equipe_pesee'], ['bienvenue', 'statistiques', 'ma-tournee', 'pesee']],
            'équipe packaging' => [['benevole', 'equipe_packaging'], ['bienvenue', 'statistiques', 'ma-tournee', 'packaging']],
            'équipe chargement' => [['benevole', 'equipe_chargement'], ['bienvenue', 'statistiques', 'ma-tournee', 'chargement']],
            'gestionnaire' => [['gestionnaire'], ['bienvenue', 'dossiers', 'statistiques', 'ma-tournee', 'reception', 'pesee', 'packaging', 'chargement', 'gestion']],
            'admin' => [['admin'], GuideContenu::SECTIONS],
        ];
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $attendues
     */
    #[DataProvider('personas')]
    public function test_chaque_role_recoit_uniquement_ses_sections(array $roles, array $attendues): void
    {
        $this->assertSame($attendues, $this->idsRecus($this->creerPersonne($roles)));
    }

    public function test_un_invite_est_redirige_vers_la_connexion(): void
    {
        $this->get(route('guide.index'))->assertRedirect(route('login'));
    }

    public function test_une_personne_affectee_a_une_equipe_sans_le_role_global_voit_son_poste(): void
    {
        $personne = $this->creerPersonne(['benevole']);
        $campagne = Campagne::create(['type' => 'zakat_el_fitr', 'statut' => 'en_cours', 'date_livraison' => '2026-11-10']);
        CampagneEquipeMembre::create(['id_campagne' => $campagne->id, 'id_personne' => $personne->id, 'role' => 'equipe_pesee']);

        $ids = $this->idsRecus($personne);

        $this->assertContains('pesee', $ids);
        $this->assertNotContains('packaging', $ids);
    }

    public function test_le_texte_d_un_role_superieur_n_est_jamais_envoye(): void
    {
        $contenuAdmin = json_encode(GuideContenu::sectionsPour($this->creerPersonne(['admin'])), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Journal d\'audit', (string) $contenuAdmin);

        foreach ([['benevole'], ['membre'], ['gestionnaire']] as $roles) {
            $contenu = json_encode(GuideContenu::sectionsPour($this->creerPersonne($roles)), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Journal d\'audit', (string) $contenu, 'Rôles : ' . implode(',', $roles));
        }

        $chauffeur = json_encode(GuideContenu::sectionsPour($this->creerPersonne(['benevole'])), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Générer les routes', (string) $chauffeur);
        $this->assertStringNotContainsString('Reste à conditionner', (string) $chauffeur);
    }

    public function test_une_equipe_ne_recoit_pas_le_texte_des_autres_postes(): void
    {
        $pesee = json_encode(GuideContenu::sectionsPour($this->creerPersonne(['benevole', 'equipe_pesee'])), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Accéder à l\'écran de pesée', (string) $pesee);
        $this->assertStringNotContainsString('Marquer les colis prêts', (string) $pesee);
        $this->assertStringNotContainsString('Charger une tournée', (string) $pesee);
    }

    public function test_chaque_section_a_des_points_avec_un_titre_et_un_contenu(): void
    {
        foreach (GuideContenu::sectionsPour($this->creerPersonne(['admin'])) as $section) {
            $this->assertNotSame('', $section['title']);
            $this->assertNotEmpty($section['items'], "Section vide : {$section['id']}");
            foreach ($section['items'] as $item) {
                $this->assertNotSame('', trim($item['title']));
                $this->assertNotSame('', trim($item['html']), "Point sans contenu : {$item['title']}");
            }
        }
    }

    public function test_les_sections_sont_dans_l_ordre_d_affichage_sans_doublon(): void
    {
        $ids = GuideContenu::idsPour($this->creerPersonne(['admin']));

        $this->assertSame(GuideContenu::SECTIONS, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_aucune_section_sans_personne_connectee(): void
    {
        $this->assertSame([], GuideContenu::sectionsPour(null));
    }

    public function test_la_route_du_guide_est_celle_que_cherche_la_sidebar_partagee(): void
    {
        $this->assertSame('guide.index', config('amana-shared.guide_route', 'guide.index'));
        $this->assertSame(url('/guide'), route('guide.index'));
    }
}
