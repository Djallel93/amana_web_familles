<?php
// app/Services/GoogleContactsService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use Amana\Shared\Models\Setting;
use Google\Client as GoogleClient;
use Google\Service\PeopleService;
use Google\Service\PeopleService\Address;
use Google\Service\PeopleService\ContactGroup;
use Google\Service\PeopleService\CreateContactGroupRequest;
use Google\Service\PeopleService\EmailAddress;
use Google\Service\PeopleService\Membership;
use Google\Service\PeopleService\ModifyContactGroupMembersRequest;
use Google\Service\PeopleService\Name;
use Google\Service\PeopleService\PhoneNumber;
use Google\Service\PeopleService\Person;
use Google\Service\PeopleService\UserDefined;
use Illuminate\Support\Facades\Log;

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
 * (synchronisation à l'enregistrement d'un dossier), donc le coût d'un
 * aller-retour OAuth supplémentaire par job est négligeable et évite d'avoir
 * à gérer l'expiration d'un access token mis en cache.
 *
 * Labels/groupes Google Contacts (repris de l'ancien projet Google Apps
 * Script amana_familles — src/services/contactService.js) — décision du
 * 14/08/2026 : chaque contact synchronisé porte
 *   - le label fixe MAIN_GROUP_NAME, sur tous les contacts synchronisés
 *     (permet de sélectionner "toutes les familles synchronisées" d'un
 *     coup dans Google Contacts, notamment pour reverseSync — voir
 *     ReverseSyncService) ;
 *   - un label de statut = famille.etat_dossier (ex: "Validé") ;
 *   - un label de localisation "{Ville} - {Secteur}" si le quartier est
 *     résolu (id_quartier renseigné), absent sinon.
 * Contrairement à l'ancien système, il n'y a plus de labels
 * "Adultes"/"Enfants"/"Criticité"/etc. en userDefined : seuls Étudiant et
 * Hôtel sont synchronisés en tant que champs personnalisés (décision du
 * 14/08/2026 — cf. échange avec l'équipe : "All I want is Phone, adress,
 * etudiant, hotel and email").
 */
class GoogleContactsService
{
    private const SETTING_KEY = 'google_contacts_refresh_token';
    private const SETTING_APP = 'familles';

    /**
     * Label fixe appliqué à tout contact synchronisé par cette application
     * — équivalent du groupe "Famille dans le besoin" de l'ancien système
     * Google Apps Script. Sert de périmètre de scan pour ReverseSyncService
     * (on ne réconcilie que les contacts que l'appli a elle-même créés/
     * gérés, pas n'importe quel contact du carnet).
     */
    public const MAIN_GROUP_NAME = 'Famille dans le besoin';

    /**
     * Clés des champs personnalisés (userDefined) — utilisées à la fois en
     * écriture (buildPerson) et en lecture (ReverseSyncService::extraireDonneesContact).
     */
    public const CHAMP_ETUDIANT = 'Étudiant';
    public const CHAMP_HOTEL = 'Hôtel';

    /**
     * Champs LUS depuis Google (personFields d'un people.get) — inclut
     * memberships (labels) et userDefined (Étudiant/Hôtel), nécessaires en
     * lecture pour appliquerLabels() (connaître les memberships actuels
     * avant de les faire converger) et pour ReverseSyncService.
     */
    private const PERSON_FIELDS_LECTURE = 'names,phoneNumbers,emailAddresses,addresses,memberships,userDefined';

    /**
     * Champs ÉCRITS via people.updateContact (updatePersonFields) —
     * volontairement SANS 'memberships', contrairement à une version
     * précédente de ce fichier (bug corrigé le 14/08/2026, cf. erreur
     * Google "Can not remove all contact group memberships").
     *
     * En effet, contrairement à ce qu'on pourrait attendre, inclure un
     * champ dans updatePersonFields sans le renseigner sur l'objet Person
     * envoyé (buildPerson() ne positionne jamais 'memberships' — les
     * groupes sont gérés à part, via contactGroups.members.modify dans
     * appliquerLabels()) ne signifie pas "laisser ce champ inchangé" mais
     * "remplacer ce champ par sa valeur dans la requête", c'est-à-dire ici
     * une liste vide ⇒ Google tente de retirer TOUTES les appartenances
     * aux groupes du contact en un seul appel, ce que l'API refuse
     * (un contact doit toujours appartenir à au moins un groupe) — d'où
     * l'échec systématique de people.updateContact() constaté, avant même
     * d'atteindre la logique de labels dans appliquerLabels().
     */
    private const PERSON_FIELDS_ECRITURE = 'names,phoneNumbers,emailAddresses,addresses,userDefined';

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
     * Client PeopleService authentifié — exposé (au lieu de rester privé)
     * pour permettre à ReverseSyncService de composer plusieurs appels
     * (get + résolution de labels) contre un seul client, sans dupliquer
     * la logique de rafraîchissement de token.
     */
    public function peopleService(): PeopleService
    {
        return new PeopleService($this->authenticatedClient());
    }

    /**
     * Crée un nouveau contact Google à partir d'une famille et retourne son
     * resourceName ("people/c...") — à persister dans
     * familles.google_resource_name par l'appelant (le job).
     *
     * Applique aussi les labels (groupe principal + statut + localisation)
     * juste après la création — un contact fraîchement créé n'a par
     * définition aucune appartenance à retirer, contrairement à
     * updateContact().
     */
    public function createContact(Famille $famille): string
    {
        $service = $this->peopleService();

        $created = $service->people->createContact(
            $this->buildPerson($famille),
            ['personFields' => self::PERSON_FIELDS_LECTURE]
        );

        $resourceName = $created->getResourceName();
        $this->appliquerLabels($service, $famille, $resourceName, []);

        return $resourceName;
    }

    /**
     * Met à jour le contact Google existant d'une famille
     * (famille.google_resource_name doit déjà être renseigné).
     *
     * People API exige l'etag du contact existant pour toute mise à jour
     * (protection contre les écrasements concurrents côté Google) — d'où le
     * get() préalable. Ce même get() sert aussi à connaître les labels
     * actuellement portés par le contact (memberships), pour ne retirer que
     * ceux gérés par cette application (statut/localisation) sans toucher à
     * un label ajouté manuellement par un admin dans Google Contacts.
     */
    public function updateContact(Famille $famille): void
    {
        if (!$famille->google_resource_name) {
            throw new \RuntimeException(
                "updateContact appelé sans google_resource_name pour la famille #{$famille->id}"
            );
        }

        $service = $this->peopleService();

        $existant = $service->people->get(
            $famille->google_resource_name,
            ['personFields' => self::PERSON_FIELDS_LECTURE]
        );

        $person = $this->buildPerson($famille);
        $person->setEtag($existant->getEtag());

        $service->people->updateContact(
            $famille->google_resource_name,
            $person,
            ['updatePersonFields' => self::PERSON_FIELDS_ECRITURE]
        );

        $this->appliquerLabels($service, $famille, $famille->google_resource_name, $existant->getMemberships() ?? []);
    }

    /**
     * Lit un contact Google par resourceName ("people/c...") — R de CRUD,
     * pas utilisé par le pipeline de synchronisation lui-même (qui ne fait
     * que create/update), mais nécessaire pour vérifier un round-trip
     * complet en conditions réelles (cf. familles:tester-contacts-google)
     * et pour ReverseSyncService::scanner().
     */
    public function getContact(string $resourceName): Person
    {
        $service = $this->peopleService();

        return $service->people->get($resourceName, ['personFields' => self::PERSON_FIELDS_LECTURE]);
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
        $service = $this->peopleService();

        $service->people->deleteContact($resourceName);
    }

    /**
     * Résout un ensemble de resourceNames de groupes ("contactGroups/...")
     * vers leurs noms lisibles ("Validé", "Nantes - Centre", ...) — un seul
     * appel batchGet plutôt qu'un get() par groupe. Utilisé par
     * ReverseSyncService pour afficher/comparer le statut et la
     * localisation actuellement portés par un contact.
     *
     * @param string[] $resourceNames
     * @return array<string,string> resourceName => nom du groupe
     */
    public function resoudreNomsGroupes(PeopleService $service, array $resourceNames): array
    {
        if (empty($resourceNames)) {
            return [];
        }

        $reponse = $service->contactGroups->batchGet(['resourceNames' => $resourceNames]);
        $noms = [];

        foreach ($reponse->getResponses() as $item) {
            $groupe = $item->getContactGroup();
            if ($groupe) {
                $noms[$groupe->getResourceName()] = $groupe->getName();
            }
        }

        return $noms;
    }

    // ── Construction du contact ──────────────────────────────────────────

    /**
     * Traduit les champs Famille (nom, prenom, telephone, telephone_bis,
     * email, adresse, etudiant, est_hotel) vers un objet Person People API.
     *
     * id_quartier n'a pas d'équivalent direct côté Google Contacts (c'est
     * une donnée de résolution géographique interne AMANA, pas une adresse
     * postale) — seuls adresse/code_postal/ville_texte (bruts, saisis par
     * la famille) sont envoyés dans le champ adresse du contact ; le
     * quartier se retrouve indirectement via le label de localisation
     * (voir appliquerLabels()).
     *
     * Convention de nommage reprise telle quelle de l'ancien système
     * Google Apps Script (amana_familles/Google_Sheets/src/services/
     * contactService.js, _buildContactResource) — "{ID} - {Prénom} {Nom}",
     * ID préfixé sur 3 chiffres (padding cosmétique, comme dans l'ancien
     * système ; un id à 4 chiffres ou plus s'affiche simplement tel quel,
     * str_pad ne tronque jamais). L'ID est stocké dans givenName (préfixe
     * "{id} -") pour rester repérable/parsable dans Google Contacts, sans
     * forcer de casse particulière sur le nom/prénom (contrairement à
     * l'ancien système qui ne changeait pas la casse non plus, en réalité —
     * demande explicite du 14/08/2026 : "same case").
     */
    private function buildPerson(Famille $famille): Person
    {
        $idAffiche = str_pad((string) $famille->id, 3, '0', STR_PAD_LEFT);

        $phoneNumbers = [
            new PhoneNumber(['value' => $famille->telephone, 'type' => 'mobile']),
        ];

        if ($famille->telephone_bis) {
            $phoneNumbers[] = new PhoneNumber(['value' => $famille->telephone_bis, 'type' => 'other']);
        }

        $fields = [
            'names' => [
                new Name([
                    'givenName' => "{$idAffiche} -",
                    'middleName' => $famille->prenom,
                    'familyName' => $famille->nom,
                ]),
            ],
            'phoneNumbers' => $phoneNumbers,
            'userDefined' => [
                new UserDefined(['key' => self::CHAMP_ETUDIANT, 'value' => $famille->etudiant ? 'Oui' : 'Non']),
                new UserDefined(['key' => self::CHAMP_HOTEL, 'value' => $famille->est_hotel ? 'Oui' : 'Non']),
            ],
        ];

        if ($famille->email) {
            $fields['emailAddresses'] = [
                new EmailAddress(['value' => $famille->email]),
            ];
        }

        if ($famille->adresse) {
            $decomposee = $this->decomposerAdresse($famille);
            $fields['addresses'] = [
                new Address([
                    'streetAddress' => $decomposee['rue'],
                    'postalCode' => $decomposee['code_postal'],
                    'city' => $decomposee['ville'],
                    'country' => 'France',
                    'countryCode' => 'FR',
                    'type' => 'home',
                ]),
            ];
        }

        return new Person($fields);
    }

    /**
     * Décompose l'adresse d'une famille en rue/code postal/ville pour le
     * contact Google — deux cas selon comment le dossier a été renseigné :
     *
     *   1. Cas normal : code_postal et ville_texte sont des colonnes
     *      dédiées, remplies soit automatiquement par l'autocomplétion
     *      Google Places, soit à la main (voir DetailPanel.vue,
     *      resources/js/components/familles/DetailPanel.vue ~L751-766) —
     *      adresse ne contient alors que la voie/numéro. On fait confiance
     *      à ces colonnes telles quelles.
     *
     *   2. Dossiers plus anciens ou importés (décision 6.8 —
     *      FamillesController::update() accepte code_postal/ville_texte
     *      nullable pour ne pas bloquer l'édition d'un dossier créé avant
     *      l'ajout de ces colonnes) où seul le champ libre `adresse` a
     *      été renseigné, avec l'adresse complète dedans (ex: "12 rue de
     *      la Paix, 44000 Nantes") — sans ce cas, la totalité de l'adresse
     *      atterrissait dans streetAddress côté Google Contacts, avec
     *      Code postal/Ville vides (bug rapporté le 14/08/2026). On tente
     *      donc d'extraire un code postal français (5 chiffres) suivi
     *      d'une ville en fin de chaîne ; à défaut de correspondance,
     *      l'adresse complète reste dans streetAddress (comportement
     *      identique à avant plutôt qu'une perte de données).
     *
     * @return array{rue: ?string, code_postal: ?string, ville: ?string}
     */
    public function decomposerAdresse(Famille $famille): array
    {
        if ($famille->code_postal || $famille->ville_texte) {
            return [
                'rue' => $famille->adresse,
                'code_postal' => $famille->code_postal,
                'ville' => $famille->ville_texte,
            ];
        }

        // Motif : "<rue...>, 44000 Nantes" ou "<rue...> 44000 Nantes" —
        // le séparateur avant le code postal (virgule ou espaces) est
        // optionnel, la ville est tout ce qui suit le code postal.
        if (preg_match('/^(.*?)[,\s]+(\d{5})\s+(.+)$/u', trim($famille->adresse), $matches)) {
            return [
                'rue' => trim($matches[1], " ,\t\n\r\0\x0B") ?: null,
                'code_postal' => $matches[2],
                'ville' => trim($matches[3]),
            ];
        }

        return ['rue' => $famille->adresse, 'code_postal' => null, 'ville' => null];
    }

    // ── Labels (contact groups) ──────────────────────────────────────────

    /**
     * Nom du label de statut pour une famille donnée — un par valeur
     * possible de etat_dossier (ex: "Validé"), identique au libellé
     * affiché côté app pour rester lisible dans Google Contacts.
     */
    private function nomLabelStatut(Famille $famille): ?string
    {
        return $famille->etat_dossier ?: null;
    }

    /**
     * Nom du label de localisation "{Ville} - {Secteur}", ou null si le
     * quartier n'est pas (encore) résolu — mêmes deux niveaux de
     * granularité que l'ancien système (pas le quartier lui-même : trop
     * fin pour être un label pratique, la ville+secteur suffit à filtrer
     * dans Google Contacts). Nécessite quartier.secteur.ville chargé ou
     * chargeable — un accès direct sur $famille->quartier déclenche un
     * lazy-load si besoin (volume faible, un contact à la fois).
     */
    private function nomLabelLocalisation(Famille $famille): ?string
    {
        $quartier = $famille->quartier;
        if (!$quartier) {
            return null;
        }

        $secteur = $quartier->secteur;
        $ville = $secteur?->ville;
        if (!$secteur || !$ville) {
            return null;
        }

        return "{$ville->nom} - {$secteur->nom}";
    }

    /**
     * Renvoie l'ensemble des noms de labels souhaités pour une famille —
     * label principal (toujours), statut (toujours), localisation (si
     * résolue). Utilisé à la fois pour appliquer les labels (appliquerLabels)
     * et par ReverseSyncService pour connaître la valeur "côté DB" du champ
     * statut/localisation à comparer.
     *
     * @return string[]
     */
    public function labelsSouhaites(Famille $famille): array
    {
        $labels = [self::MAIN_GROUP_NAME];

        // Garde-fou pour les instances Famille en mémoire, jamais
        // persistées (ex: familles:tester-contacts-google), où
        // etat_dossier n'a pas encore reçu sa valeur par défaut de
        // colonne ('Recu') — on ne veut pas créer un groupe au nom vide.
        $statut = $this->nomLabelStatut($famille);
        if ($statut) {
            $labels[] = $statut;
        }

        $localisation = $this->nomLabelLocalisation($famille);
        if ($localisation) {
            $labels[] = $localisation;
        }

        return $labels;
    }

    /**
     * Récupère (ou crée) le groupe de contacts nommé $nom et renvoie son
     * resourceName. Pas de cache applicatif contrairement à l'ancien
     * système Google Apps Script (CacheService) : le volume d'appels reste
     * faible ici (un contact synchronisé à la fois, à l'enregistrement d'un
     * dossier), la liste des groupes existants tient dans un seul appel
     * contactGroups->listContactGroups().
     */
    private function getOuCreerGroupe(PeopleService $service, string $nom): string
    {
        $existants = $service->contactGroups->listContactGroups(['pageSize' => 1000]);

        foreach ($existants->getContactGroups() ?? [] as $groupe) {
            if ($groupe->getName() === $nom) {
                return $groupe->getResourceName();
            }
        }

        $cree = $service->contactGroups->create(
            new CreateContactGroupRequest(['contactGroup' => new ContactGroup(['name' => $nom])])
        );

        return $cree->getResourceName();
    }

    /**
     * Fait converger les labels (contact groups) d'un contact vers
     * labelsSouhaites($famille) : ajoute les labels manquants, retire ceux
     * gérés par l'application (statut/localisation précédents) qui ne sont
     * plus d'actualité. Ne touche jamais à un label qui n'est ni le label
     * principal, ni une valeur de Famille::ETATS, ni au format
     * "X - Y" (heuristique de détection des labels de localisation, reprise
     * de l'ancien système — locationGroupPattern) : un label ajouté
     * manuellement par un admin dans Google Contacts reste donc intact.
     *
     * @param Membership[] $membershipsActuels memberships déjà connus du
     *        contact (vide pour un contact tout juste créé) — évite un
     *        second appel réseau quand l'appelant les a déjà (updateContact
     *        les a via son get() préalable pour l'etag).
     */
    private function appliquerLabels(PeopleService $service, Famille $famille, string $resourceName, array $membershipsActuels): void
    {
        $labelsSouhaites = $this->labelsSouhaites($famille);

        $resourceNamesActuels = array_values(array_filter(array_map(
            fn(Membership $m) => $m->getContactGroupMembership()?->getContactGroupResourceName(),
            $membershipsActuels
        )));
        $nomsActuels = $this->resoudreNomsGroupes($service, $resourceNamesActuels);

        // Labels "gérés" par l'appli parmi ceux actuellement portés par le
        // contact : le principal, un statut connu (Famille::ETATS), ou un
        // format "X - Y" (localisation) — tout le reste est laissé intact.
        $estGere = static function (string $nom): bool {
            if ($nom === self::MAIN_GROUP_NAME) {
                return true;
            }
            if (in_array($nom, Famille::ETATS, true)) {
                return true;
            }
            return (bool) preg_match('/^.+ - .+$/', $nom);
        };

        $aAjouter = array_diff($labelsSouhaites, array_values($nomsActuels));
        $aRetirer = array_filter(
            $nomsActuels,
            fn(string $nom) => $estGere($nom) && !in_array($nom, $labelsSouhaites, true)
        );

        foreach ($aAjouter as $nomLabel) {
            $resourceNameGroupe = $this->getOuCreerGroupe($service, $nomLabel);
            $service->contactGroups_members->modify(
                $resourceNameGroupe,
                new ModifyContactGroupMembersRequest(['resourceNamesToAdd' => [$resourceName]])
            );
        }

        // Retraits isolés dans leur propre try/catch (14/08/2026) : Google
        // refuse toute suppression de membership qui laisserait le contact
        // sans AUCUN groupe ("Can not remove all contact group
        // memberships"), erreur rencontrée en pratique alors que
        // labelsSouhaites() garantit pourtant toujours MAIN_GROUP_NAME côté
        // ajout — l'API contactGroups.members semble ne pas garantir la
        // cohérence immédiate entre l'ajout ci-dessus et la lecture faite
        // par la validation du retrait qui suit. Plutôt que de laisser
        // cette erreur remonter et faire échouer TOUTE la synchronisation
        // de la famille (alors que les champs personne, eux, ont déjà été
        // écrits avec succès par people.updateContact juste avant), on
        // journalise et on passe au label suivant : un label devenu
        // obsolète qui traîne encore sur le contact n'est pas grave (il
        // sera retiré au prochain passage), un échec total silencieux
        // côté staff, si.
        foreach (array_keys($aRetirer) as $resourceNameGroupe) {
            try {
                $service->contactGroups_members->modify(
                    $resourceNameGroupe,
                    new ModifyContactGroupMembersRequest(['resourceNamesToRemove' => [$resourceName]])
                );
            } catch (\Throwable $e) {
                Log::warning('[GoogleContactsService] Retrait de label ignoré (non bloquant)', [
                    'id_famille' => $famille->id,
                    'resource_name_contact' => $resourceName,
                    'resource_name_groupe' => $resourceNameGroupe,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }
    }
}
