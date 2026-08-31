<?php
// app/Support/TokenHasher.php

declare(strict_types=1);

namespace App\Support;

/**
 * Hachage des jetons publics à usage unique (email de confirmation famille/
 * vérification/candidature bénévole/contact livraison) — ajouté le
 * 31/08/2026 suite à l'audit des 3 flux existants (FamilleVerification,
 * IntakeDemandeAttente, BenevoleDemandeAttente), tous stockaient
 * auparavant le jeton en clair en base.
 *
 * SHA-256 simple (pas bcrypt/argon2) : contrairement à un mot de passe,
 * ces jetons sont déjà des chaînes aléatoires longues (48-60 caractères,
 * Str::random()) — le risque visé n'est pas le brute-force en ligne mais
 * l'exfiltration de la base (dump, sauvegarde mal protégée...) : un hash
 * simple et déterministe suffit, et permet une comparaison directe par
 * `WHERE token = ?` sans salage par ligne (contrairement à bcrypt, qui
 * empêcherait justement ce type de lookup direct).
 *
 * Le jeton EN CLAIR ne doit jamais être repersisté après génération — il
 * ne doit exister que dans l'email envoyé et, transitoirement, en mémoire
 * le temps de construire cet email (voir les Notifications de chacun des
 * 3 flux, qui reçoivent désormais le jeton en clair en paramètre de
 * constructeur séparé du modèle, plutôt que de le relire depuis
 * $modele->token qui ne contient plus que le hash).
 */
final class TokenHasher
{
    public static function hash(string $tokenEnClair): string
    {
        return hash('sha256', $tokenEnClair);
    }
}
