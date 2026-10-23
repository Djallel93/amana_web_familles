<?php
// tests/Feature/Models/IncidentTypesTest.php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\RouteIncident;
use Tests\TestCase;

/**
 * 23/10/2026 — la liste des types d'incident vit à cinq endroits (enum de la migration, RouteIncident,
 * libellés, guides, constante TS) : ces tests échouent dès qu'un nouveau type n'est ajouté que partiellement.
 */
class IncidentTypesTest extends TestCase
{
    public function test_chaque_type_a_un_libelle_et_une_description(): void
    {
        foreach (RouteIncident::TYPES as $type) {
            $this->assertArrayHasKey($type, RouteIncident::LABELS_TYPE, "Libellé manquant pour {$type}");
            // Le jalon chargement_termine n'est jamais listé : il n'a pas de description.
            if (in_array($type, RouteIncident::TYPES_SANS_STATUT, true)) {
                continue;
            }
            $incident = (new RouteIncident())->forceFill(['type' => $type]);
            $this->assertNotSame('Incident de tournée.', $incident->description(), "Description manquante pour {$type}");
        }
        $this->assertEqualsCanonicalizing(RouteIncident::TYPES, array_keys(RouteIncident::LABELS_TYPE));
    }

    public function test_chaque_type_actionnable_a_un_guide_et_le_jalon_n_en_a_pas(): void
    {
        foreach (RouteIncident::TYPES as $type) {
            $guide = (new RouteIncident())->forceFill(['type' => $type])->guide();
            if (in_array($type, RouteIncident::TYPES_SANS_STATUT, true)) {
                $this->assertNull($guide);
            } else {
                $this->assertNotEmpty($guide, "Guide manquant pour {$type}");
                $this->assertStringStartsWith('1. ', $guide);
            }
        }
    }

    public function test_les_types_detectes_sont_des_types_connus(): void
    {
        $this->assertSame([], array_diff(RouteIncident::TYPES_DETECTES, RouteIncident::TYPES));
    }

    public function test_la_migration_et_la_constante_typescript_listent_les_memes_types(): void
    {
        $migration = (string) file_get_contents(database_path('migrations/2026_08_31_000009_create_livraison_routing_tables.php'));
        preg_match("/enum\('type', \[(.*?)\]\)/s", $migration, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $enum);

        $ts = (string) file_get_contents(resource_path('js/components/livraison/shared/types.ts'));
        preg_match('/TYPES_INCIDENT = \[(.*?)\] as const/s', $ts, $m);
        preg_match_all('/"([a-z_]+)"/', $m[1] ?? '', $constante);

        $attendus = RouteIncident::TYPES;
        sort($attendus);
        $enumTypes = $enum[1];
        $tsTypes = $constante[1];
        sort($enumTypes);
        sort($tsTypes);

        $this->assertSame($attendus, $enumTypes, 'enum de la migration désynchronisé');
        $this->assertSame($attendus, $tsTypes, 'TYPES_INCIDENT (types.ts) désynchronisé');
    }
}
