<?php
// app/Services/GoogleContactsService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use Amana\Shared\Models\Setting;
use Google\Client as GoogleClient;
use Google\Service\PeopleService;
use Google\Service\PeopleService\Address;
use Google\Service\PeopleService\EmailAddress;
use Google\Service\PeopleService\Name;
use Google\Service\PeopleService\PhoneNumber;
use Google\Service\PeopleService\Person;

/**
 * Intégration directe Google People API — remplace l'ancien webhook
 * Make.com de synchronisation de contact (décision du 17/07/2026,
 * cf. SynchroniserContactGoogle, ex-EnvoyerWebhookContact).
 *
 * Contrairement au géocodage (ResoudreAdresseFamille), qui reste sur
 * Make.com (l'API Google Maps Geocoding nécessite un compte de facturation),
 * la création/mise à jour/recherche de contacts People API est gratuite et
 * gouvernée par quotas — pas de carte bancaire requise (décision 3).
 *
 * Le contact appartient à un compte Google précis (amana44.pole.social@
 * gmail.com), pas au projet GCP lui-même : d'où le flux OAuth 2.0
 * (scope people.CONTACTS) et le refresh token stocké chiffré dans
 * ref_settings (cle='google_contacts_refresh_token', app='familles') —
 * voir Setting::setEncrypted() et Admin\GoogleContactsController.
 *
 * Chaque appel récupère un nouvel access token via le refresh token stocké
 * (pas de cache d'access token côté application) : volumétrie faible
 * (synchronisation à la validation d'un dossier), donc le coût d'un aller-
 * retour OAuth supplémentaire par job est négligeable et évite d'avoir à
 * gérer l'expiration d'un access token mis en cache.
 */
class GoogleContactsService
{
    private const SETTING_KEY = 'google_contacts_refresh_token';
    private const SETTING_APP = 'familles';

    /**
     * Champs synchronisés dans les deux sens (création ET mise à jour) —
     * doit rester identique entre personFields (create/get) et
     * updatePersonFields (update), sans quoi People API rejette la requête.
     */
    private const PERSON_FIELDS = 'names,phoneNumbers,emailAddresses,addresses';

    /**
     * true si le client OAuth (client_id/secret) est configuré ET qu'un
     * refresh token a déjà été enregistré (flux d'autorisation effectué).
     * Le job de synchronisation s'appuie dessus pour échouer proprement
     * (log + skip, pas de retry inutile) tant que l'admin n'a pas encore
     * autorisé le compte Google via /admin/google-contacts/authorize.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.google.contacts.client_id')
            && (bool) config('services.google.contacts.client_secret')
            && (bool) Setting::get(self::SETTING_KEY, self::SETTING_APP);
    }

    /**
     * Client Google non authentifié — utilisé pour générer l'URL de
     * consentement (createAuthUrl) et pour l'échange initial code→token
     * dans Admin\GoogleContactsController. Pas de refresh token requis ici.
     */
    public function createClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google.contacts.client_id'));
        $client->setClientSecret(config('services.google.contacts.client_secret'));
        $client->setRedirectUri(config('services.google.contacts.redirect_uri'));
        $client->setScopes([PeopleService::CONTACTS]);
        $client->setAccessType('offline');
        // 'consent' force Google à renvoyer un refresh_token même si le
        // compte a déjà autorisé cette appli par le passé (sinon Google ne
        // le renvoie qu'à la toute première autorisation).
        $client->setPrompt('consent');

        return $client;
    }

    /**
     * Client authentifié via le refresh token stocké — utilisé par le job
     * de synchronisation pour les appels createContact/updateContact/get.
     * Le SDK google/apiclient rafraîchit l'access token automatiquement à
     * partir du refresh token (décision 5).
     *
     * @throws \RuntimeException si aucun refresh token n'est encore enregistré
     */
    private function authenticatedClient(): GoogleClient
    {
        $refreshToken = Setting::get(self::SETTING_KEY, self::SETTING_APP);

        if (!$refreshToken) {
            throw new \RuntimeException(
                'Google Contacts non autorisé — exécuter le flux OAuth via /admin/google-contacts/authorize.'
            );
        }

        $client = $this->createClient();
        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($token['error'])) {
            throw new \RuntimeException(
                'Échec du rafraîchissement du token Google Contacts : ' . $token['error']
            );
        }

        return $client;
    }

    /**
     * Crée un nouveau contact Google à partir d'une famille et retourne son
     * resourceName ("people/c...") — à persister dans
     * familles.google_resource_name par l'appelant (le job).
     */
    public function createContact(Famille $famille): string
    {
        $service = new PeopleService($this->authenticatedClient());

        $created = $service->people->createContact(
            $this->buildPerson($famille),
            ['personFields' => self::PERSON_FIELDS]
        );

        return $created->getResourceName();
    }

    /**
     * Met à jour le contact Google existant d'une famille
     * (famille.google_resource_name doit déjà être renseigné).
     *
     * People API exige l'etag du contact existant pour toute mise à jour
     * (protection contre les écrasements concurrents côté Google) — d'où le
     * get() préalable.
     */
    public function updateContact(Famille $famille): void
    {
        if (!$famille->google_resource_name) {
            throw new \RuntimeException(
                "updateContact appelé sans google_resource_name pour la famille #{$famille->id}"
            );
        }

        $service = new PeopleService($this->authenticatedClient());

        $existant = $service->people->get(
            $famille->google_resource_name,
            ['personFields' => self::PERSON_FIELDS]
        );

        $person = $this->buildPerson($famille);
        $person->setEtag($existant->getEtag());

        $service->people->updateContact(
            $famille->google_resource_name,
            $person,
            ['updatePersonFields' => self::PERSON_FIELDS]
        );
    }

    /**
     * Lit un contact Google par resourceName ("people/c...") — R de CRUD,
     * pas utilisé par le pipeline de synchronisation lui-même (qui ne fait
     * que create/update), mais nécessaire pour vérifier un round-trip
     * complet en conditions réelles (cf. familles:tester-contacts-google).
     */
    public function getContact(string $resourceName): Person
    {
        $service = new PeopleService($this->authenticatedClient());

        return $service->people->get($resourceName, ['personFields' => self::PERSON_FIELDS]);
    }

    /**
     * Supprime un contact Google par resourceName — D de CRUD. Jamais
     * appelé par le pipeline de synchronisation (une famille n'est jamais
     * supprimée du côté Google automatiquement), uniquement utile pour
     * nettoyer un contact de test après un round-trip de vérification
     * (cf. familles:tester-contacts-google).
     */
    public function deleteContact(string $resourceName): void
    {
        $service = new PeopleService($this->authenticatedClient());

        $service->people->deleteContact($resourceName);
    }

    // ── Construction du contact ──────────────────────────────────────────

    /**
     * Traduit les champs Famille (nom, prenom, telephone, telephone_bis,
     * email, adresse, id_quartier) vers un objet Person People API.
     *
     * id_quartier n'a pas d'équivalent direct côté Google Contacts (c'est
     * une donnée de résolution géographique interne AMANA, pas une adresse
     * postale) — seuls adresse/code_postal/ville_texte (bruts, saisis par
     * la famille) sont envoyés dans le champ adresse du contact.
     */
    private function buildPerson(Famille $famille): Person
    {
        $phoneNumbers = [
            new PhoneNumber(['value' => $famille->telephone, 'type' => 'mobile']),
        ];

        if ($famille->telephone_bis) {
            $phoneNumbers[] = new PhoneNumber(['value' => $famille->telephone_bis, 'type' => 'other']);
        }

        $fields = [
            'names' => [
                new Name(['givenName' => $famille->prenom, 'familyName' => $famille->nom]),
            ],
            'phoneNumbers' => $phoneNumbers,
        ];

        if ($famille->email) {
            $fields['emailAddresses'] = [
                new EmailAddress(['value' => $famille->email]),
            ];
        }

        if ($famille->adresse) {
            $fields['addresses'] = [
                new Address([
                    'streetAddress' => $famille->adresse,
                    'postalCode' => $famille->code_postal,
                    'city' => $famille->ville_texte,
                    'country' => 'France',
                    'countryCode' => 'FR',
                    'type' => 'home',
                ]),
            ];
        }

        return new Person($fields);
    }
}
