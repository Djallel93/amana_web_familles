<?php
// phpstan/Rules/NoForeignIdOnCrossDatabaseColumnsRule.php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Règle PHPStan ajoutée le 10/09/2026 (Section E1 du refactor) pour la
 * convention `unsignedInteger` (pas `foreignId`) sur toute colonne
 * référençant ref_personnes/ref_roles/etc. dans la connexion `commun` —
 * jusqu'ici gardée uniquement par des commentaires dans chaque migration
 * (voir par exemple familles.locked_by, famille_imports.uploaded_by,
 * campagne_arrivees.logge_par). `foreignId()` pose une contrainte FK
 * réelle, ce qui échoue silencieusement à la migration dès que la colonne
 * référencée vit dans une base différente (MySQL ne supporte pas les
 * contraintes FK cross-database) — cette règle attrape l'erreur à
 * l'analyse statique plutôt qu'à `php artisan migrate`.
 *
 * Liste fermée plutôt qu'une heuristique de nommage (ex: "toute colonne
 * id_xxx") : une heuristique large produirait des faux positifs sur les
 * vraies FK locales de ce projet (id_famille, id_campagne, id_route...),
 * qui sont légitimement des foreignId(). Cette liste reprend les colonnes
 * cross-DB déjà identifiées dans les migrations existantes — à
 * compléter au même endroit si une nouvelle colonne cross-DB apparaît
 * (voir CONTRIBUTING ou équivalent, à créer si cette règle devient
 * gênante à maintenir en pratique).
 */
final class NoForeignIdOnCrossDatabaseColumnsRule implements Rule
{
    private const COLONNES_CROSS_DB = [
        // ref_personnes.id (increments(), pas un id() Laravel standard)
        'locked_by', 'uploaded_by', 'submitted_by', 'traite_par', 'id_personne',
        'logge_par', 'signale_par', 'pret_par', 'id_benevole', 'id_benevole_impose',
        'id_personne_assignee',
        // ref_vehicules.id
        'id_vehicule_type',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }

        if (!$node->name instanceof Node\Identifier || $node->name->name !== 'foreignId') {
            return [];
        }

        if (!isset($node->getArgs()[0])) {
            return [];
        }

        $premierArgument = $node->getArgs()[0]->value;

        if (!$premierArgument instanceof String_) {
            return [];
        }

        $nomColonne = $premierArgument->value;

        if (!in_array($nomColonne, self::COLONNES_CROSS_DB, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "\$table->foreignId('%s') pose une contrainte FK réelle, mais '%s' référence probablement ref_personnes/ref_vehicules (connexion 'commun') plutôt qu'une table de cette base — MySQL ne supporte pas les contraintes FK cross-database, ceci échouerait à la migration. Utilisez \$table->unsignedInteger('%s') SANS ->constrained(), voir familles.locked_by pour la convention.",
                $nomColonne,
                $nomColonne,
                $nomColonne,
            ))->identifier('amana.foreignIdCrossDatabase')->build(),
        ];
    }
}
