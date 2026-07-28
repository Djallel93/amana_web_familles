<?php
// app/Console/Commands/TesterContactsGoogle.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Famille;
use App\Services\GoogleContactsService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Console\Command;

/**
 * Commande de test manuel EN CONDITIONS RÉELLES pour l'intégration directe
 * Google People API (GoogleContactsService) — round-trip CRUD complet
 * (Create → Read → Update → Read → Delete) contre un contact JETABLE
 * dédié, créé et supprimé par la commande elle-même.
 *
 * Contrairement aux tests PHPUnit (Http::fake(), aucun appel réseau réel),
 * cette commande appelle réellement l'API Google — c'est le seul moyen de
 * vérifier que le refresh token stocké est valide, que le scope autorisé
 * (contacts) est suffisant, et que le round-trip de champs (nom/téléphone/
 * email/adresse) se comporte comme attendu côté Google.
 *
 * Un Famille en mémoire (jamais persisté en base, ->makeInstance, pas
 * ->create()) sert de support à buildPerson() sans toucher à familles —
 * on ne veut pas d'une vraie famille de test dans le pipeline applicatif.
 *
 * Par défaut, le contact créé est supprimé en fin de commande (round-trip
 * complet, rien ne reste dans le carnet Google réel) — --conserver permet
 * de le garder pour inspection manuelle dans Google Contacts.
 *
 * Exemples :
 *   php artisan familles:tester-contacts-google
 *   php artisan familles:tester-contacts-google --conserver
 */
class TesterContactsGoogle extends Command
{
    protected $signature = 'familles:tester-contacts-google
        {--conserver : Ne pas supprimer le contact de test à la fin (par défaut, il est supprimé)}';

    protected $description = "Round-trip CRUD réel (create/read/update/read/delete) contre Google People API, sur un contact jetable dédié";

    private const PREFIXE_TEST = '[TEST AMANA — à supprimer]';

    public function handle(GoogleContactsService $googleContacts): int
    {
        if (!$googleContacts->isConfigured()) {
            $this->error(
                "Google Contacts non configuré/autorisé (GOOGLE_CONTACTS_CLIENT_ID/SECRET manquants, "
                . "ou flux d'autorisation jamais effectué). Voir /admin/google-contacts/authorize."
            );
            return self::FAILURE;
        }

        $suffixe = now()->format('YmdHis');
        $contactTest = $this->construireFamilleDeTest($suffixe);

        // ── CREATE ──────────────────────────────────────────────────────
        $this->line("Création d'un contact de test ({$contactTest->nom} {$contactTest->prenom})...");

        try {
            $resourceName = $googleContacts->createContact($contactTest);
        } catch (GoogleServiceException $e) {
            $this->afficherErreurGoogle('Échec de la création', $e);
            return self::FAILURE;
        }

        $this->info("Créé : {$resourceName}");
        $contactTest->forceFill(['google_resource_name' => $resourceName]);

        // ── READ (après création) ───────────────────────────────────────
        try {
            $relu = $googleContacts->getContact($resourceName);
        } catch (GoogleServiceException $e) {
            $this->afficherErreurGoogle('Échec de la lecture après création', $e);
            $this->nettoyer($googleContacts, $resourceName);
            return self::FAILURE;
        }

        $this->afficherPerson('Relu après création', $relu);

        // ── UPDATE ───────────────────────────────────────────────────────
        $contactTest->telephone = '0600000000'; // valeur modifiée, pour vérifier que l'update est bien pris en compte
        $this->line('Mise à jour du téléphone du contact de test...');

        try {
            $googleContacts->updateContact($contactTest);
        } catch (GoogleServiceException $e) {
            $this->afficherErreurGoogle('Échec de la mise à jour', $e);
            $this->nettoyer($googleContacts, $resourceName);
            return self::FAILURE;
        }

        $this->info('Mis à jour.');

        // ── READ (après mise à jour) ────────────────────────────────────
        try {
            $reluApresMaj = $googleContacts->getContact($resourceName);
        } catch (GoogleServiceException $e) {
            $this->afficherErreurGoogle('Échec de la lecture après mise à jour', $e);
            $this->nettoyer($googleContacts, $resourceName);
            return self::FAILURE;
        }

        $this->afficherPerson('Relu après mise à jour', $reluApresMaj);

        $telephoneRelu = $reluApresMaj->getPhoneNumbers()[0]?->getValue();
        if ($telephoneRelu === $contactTest->telephone) {
            $this->info('Le téléphone mis à jour correspond bien à ce qui a été envoyé.');
        } else {
            $this->warn("Le téléphone relu ({$telephoneRelu}) ne correspond pas à celui envoyé ({$contactTest->telephone}).");
        }

        // ── DELETE ───────────────────────────────────────────────────────
        if ($this->option('conserver')) {
            $this->warn("--conserver : le contact de test N'A PAS été supprimé ({$resourceName}). À nettoyer manuellement dans Google Contacts si besoin.");
            return self::SUCCESS;
        }

        return $this->nettoyer($googleContacts, $resourceName) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Construit un Famille EN MÉMOIRE (jamais sauvegardé en base) portant
     * des données manifestement fictives — utilisé uniquement comme support
     * pour GoogleContactsService::buildPerson(), qui attend un Famille.
     */
    private function construireFamilleDeTest(string $suffixe): Famille
    {
        $famille = new Famille();
        $famille->nom = self::PREFIXE_TEST;
        $famille->prenom = "Test {$suffixe}";
        $famille->telephone = '0700000000';
        $famille->telephone_bis = null;
        $famille->email = null;
        $famille->adresse = '1 rue de Test';
        $famille->code_postal = '00000';
        $famille->ville_texte = 'Ville de Test';

        return $famille;
    }

    private function nettoyer(GoogleContactsService $googleContacts, string $resourceName): bool
    {
        $this->line('Suppression du contact de test...');

        try {
            $googleContacts->deleteContact($resourceName);
        } catch (GoogleServiceException $e) {
            $this->afficherErreurGoogle(
                "Échec de la suppression — le contact de test ({$resourceName}) reste dans Google Contacts, à supprimer manuellement",
                $e
            );
            return false;
        }

        $this->info('Supprimé — round-trip CRUD complet, rien ne reste dans Google Contacts.');
        return true;
    }

    private function afficherPerson(string $titre, \Google\Service\PeopleService\Person $person): void
    {
        $nom = $person->getNames()[0] ?? null;
        $telephone = $person->getPhoneNumbers()[0]?->getValue();
        $adresse = $person->getAddresses()[0] ?? null;

        $this->table(['Champ', 'Valeur'], [
            ['Contexte', $titre],
            ['resourceName', $person->getResourceName()],
            ['Nom', $nom ? "{$nom->getGivenName()} {$nom->getFamilyName()}" : '(absent)'],
            ['Téléphone', $telephone ?? '(absent)'],
            ['Adresse', $adresse ? "{$adresse->getStreetAddress()}, {$adresse->getPostalCode()} {$adresse->getCity()}" : '(absente)'],
        ]);
    }

    private function afficherErreurGoogle(string $contexte, GoogleServiceException $e): void
    {
        $this->error("{$contexte} : {$e->getMessage()}");

        // Google\Service\Exception expose le détail JSON de l'erreur API
        // (bien plus parlant que getMessage() seul pour diagnostiquer un
        // scope insuffisant, un refresh token expiré, un quota dépassé...).
        $errors = $e->getErrors();
        if (!empty($errors)) {
            $this->line('Détail Google : ' . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
