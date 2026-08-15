<?php
// app/Services/ReverseSyncService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use Google\Service\PeopleService\Person;
use Illuminate\Support\Facades\Log;

/**
 * Sync "retour" Contact Google → Dossier — pendant équivalent, côté
 * amana_web_familles, de reverseContactScanService.js/reverseContactSyncService.js
 * dans l'ancien projet Google Apps Script (amana_familles), adapté au
 * modèle de données actuel : là où l'ancien système reconstituait l'ID
 * famille depuis le préfixe du nom du contact (regex sur givenName), ici
 * on part directement de familles.google_resource_name (fiable, posé par
 * GoogleContactsService::createContact() — pas besoin de reparser un nom).
 *
 * scanner() ne modifie rien (lecture seule, comme scanContactChanges() côté
 * GAS) — c'est appliquer() qui écrit, après que le staff a tranché chaque
 * champ en écart depuis le panneau de résolution (voir
 * ReverseSyncPanel.vue et GoogleContactsReverseSyncController).
 *
 * Volontairement HORS PÉRIMÈTRE de la comparaison : le label de
 * localisation ("{Ville} - {Secteur}", voir GoogleContactsService::
 * nomLabelLocalisation) n'est pas un champ résolvable un-à-un vers
 * id_quartier (plusieurs quartiers peuvent partager le même secteur) — ce
 * n'est qu'un label de recherche dérivé de la géocodification, pas une
 * donnée saisie qu'on pourrait "accepter" depuis Google sans perdre en
 * précision. Un futur besoin de le comparer nécessiterait un sélecteur de
 * quartier dédié côté panneau plutôt qu'un accept/reject simple.
 */
class ReverseSyncService
{
    public function __construct(
        private readonly GoogleContactsService $googleContacts
    ) {
    }

    /**
     * Définition des champs comparés — chaque entrée sait lire sa valeur
     * côté Famille (colonne directe) et normaliser la valeur lue côté
     * contact Google (déjà extraite par extraireDonneesContact()) pour une
     * comparaison fiable (ex: téléphone sans espaces/formatage).
     *
     * 'type' pilote l'affichage/la validation côté panneau (texte, booléen
     * Oui/Non, ou liste fermée pour etat_dossier).
     */
    private function definitionsChamps(): array
    {
        return [
            'nom' => ['label' => 'Nom', 'type' => 'texte'],
            'prenom' => ['label' => 'Prénom', 'type' => 'texte'],
            'telephone' => ['label' => 'Téléphone', 'type' => 'texte', 'normaliser' => fn($v) => $this->normaliserTelephone($v)],
            'telephone_bis' => ['label' => 'Téléphone secondaire', 'type' => 'texte', 'normaliser' => fn($v) => $this->normaliserTelephone($v)],
            'email' => ['label' => 'Email', 'type' => 'texte', 'normaliser' => fn($v) => $v ? strtolower(trim($v)) : null],
            'adresse' => ['label' => 'Adresse', 'type' => 'texte'],
            'code_postal' => ['label' => 'Code postal', 'type' => 'texte'],
            'ville_texte' => ['label' => 'Ville', 'type' => 'texte'],
            'etudiant' => ['label' => 'Étudiant', 'type' => 'booleen'],
            'est_hotel' => ['label' => 'Hôtel', 'type' => 'booleen'],
            'etat_dossier' => ['label' => 'Statut', 'type' => 'enum', 'options' => Famille::ETATS_SELECTIONNABLES],
        ];
    }

    private function normaliserTelephone(?string $telephone): ?string
    {
        return $telephone ? preg_replace('/\D/', '', $telephone) : null;
    }

    /**
     * Scanne toutes les familles liées à un contact Google
     * (google_resource_name renseigné) et renvoie la liste des familles
     * présentant au moins un écart — lecture seule, un appel getContact()
     * par famille (volume faible, cohérent avec l'appel individuel déjà
     * fait par familles:tester-contacts-google).
     *
     * Gère explicitement le cas d'un contact introuvable côté Google
     * (404 — resourceName périmé : contact supprimé manuellement, ou
     * provenant d'une ancienne autorisation OAuth/ancien pipeline Make.com
     * antérieur au 17/07/2026) : plutôt que d'ignorer silencieusement la
     * famille (comportement initial — se traduisait par un scan qui
     * "ne semblait rien faire" sans aucune indication à l'écran), on
     * détache google_resource_name (remis à null) pour que la prochaine
     * transition déclenchante (voir FamillesController::update()) recrée
     * un contact propre au lieu de retomber sur la même erreur à chaque
     * scan, et on remonte la famille dans une liste 'introuvables' séparée
     * pour que le panneau puisse en informer le staff.
     *
     * Structure renvoyée (sérialisable JSON, consommée par
     * ReverseSyncPanel.vue) :
     * [
     *   'diffs' => [
     *     [
     *       'id_famille' => int,
     *       'nom_complet' => string,
     *       'champs' => [
     *         ['champ' => 'telephone', 'label' => 'Téléphone', 'type' => 'texte',
     *          'valeur_db' => '0601020304', 'valeur_contact' => '0601020399'],
     *         ...
     *       ],
     *     ],
     *     ...
     *   ],
     *   'introuvables' => [
     *     ['id_famille' => int, 'nom_complet' => string],
     *     ...
     *   ],
     * ]
     */
    public function scanner(): array
    {
        $familles = Famille::whereNotNull('google_resource_name')
            ->with('quartier.secteur.ville')
            ->get();

        $service = $this->googleContacts->peopleService();
        $diffs = [];
        $introuvables = [];

        foreach ($familles as $famille) {
            try {
                $contact = $service->people->get(
                    $famille->google_resource_name,
                    ['personFields' => 'names,phoneNumbers,emailAddresses,addresses,userDefined,memberships']
                );
            } catch (\Throwable $e) {
                $estIntrouvable = $e instanceof \Google\Service\Exception && $e->getCode() === 404;

                Log::warning('[ReverseSyncService] Lecture contact impossible — ignoré', [
                    'id_famille' => $famille->id,
                    'google_resource_name' => $famille->google_resource_name,
                    'introuvable_404' => $estIntrouvable,
                    'erreur' => $e->getMessage(),
                ]);

                if ($estIntrouvable) {
                    // Auto-guérison : ce resourceName ne pointe plus vers
                    // rien côté Google, on ne veut pas rester bloqué dessus
                    // indéfiniment — la prochaine transition Validé/Rejeté/
                    // Archivé recréera un contact neuf (google_resource_name
                    // null ⇒ GoogleContactsService::createContact(), pas
                    // updateContact()).
                    $famille->forceFill(['google_resource_name' => null])->save();
                    $introuvables[] = [
                        'id_famille' => $famille->id,
                        'nom_complet' => trim("{$famille->prenom} {$famille->nom}"),
                    ];
                }

                continue;
            }

            $donneesContact = $this->extraireDonneesContact($service, $contact);
            $champsEnEcart = $this->comparer($famille, $donneesContact);

            if (!empty($champsEnEcart)) {
                $diffs[] = [
                    'id_famille' => $famille->id,
                    'nom_complet' => trim("{$famille->prenom} {$famille->nom}"),
                    'champs' => $champsEnEcart,
                ];
            }
        }

        return ['diffs' => $diffs, 'introuvables' => $introuvables];
    }

    /**
     * Extrait les valeurs comparables d'un Person Google — pendant de
     * extractContactData()/parseFamilyMetadataFromContact() côté GAS, mais
     * réduit aux seuls champs synchronisés par cette application (voir
     * GoogleContactsService::buildPerson).
     */
    private function extraireDonneesContact(\Google\Service\PeopleService $service, Person $contact): array
    {
        $noms = $contact->getNames()[0] ?? null;
        $telephones = $contact->getPhoneNumbers() ?? [];
        $emails = $contact->getEmailAddresses() ?? [];
        $adresses = $contact->getAddresses() ?? [];
        $champsPerso = $contact->getUserDefined() ?? [];

        $etudiant = null;
        $hotel = null;
        foreach ($champsPerso as $champ) {
            if ($champ->getKey() === GoogleContactsService::CHAMP_ETUDIANT) {
                $etudiant = $champ->getValue() === 'Oui';
            }
            if ($champ->getKey() === GoogleContactsService::CHAMP_HOTEL) {
                $hotel = $champ->getValue() === 'Oui';
            }
        }

        $adresse = $adresses[0] ?? null;

        // Récupération du n-ième numéro/email — volontairement PAS
        // "$telephones[$i]->getValue() ?? null" : le ?? ne supprime le
        // warning "Undefined array key" que pour un accès tableau/propriété
        // pur (style isset()), pas dès qu'un appel de méthode est chaîné
        // derrière — d'où le crash "Undefined array key 1" en prod dès
        // qu'un contact n'a qu'un seul numéro de téléphone (index 1
        // absent). isset() explicite avant l'accès, ici.
        $valeurIndex = static fn(array $items, int $index): ?string =>
            isset($items[$index]) ? $items[$index]->getValue() : null;

        // Label de statut : parmi les groupes du contact, celui dont le
        // nom correspond à une valeur connue de Famille::ETATS_SELECTIONNABLES
        // — au plus un seul label de ce type normalement (appliquerLabels()
        // ne pose qu'un statut à la fois), on prend le premier trouvé.
        $resourceNamesGroupes = array_values(array_filter(array_map(
            fn($m) => $m->getContactGroupMembership()?->getContactGroupResourceName(),
            $contact->getMemberships() ?? []
        )));
        $nomsGroupes = array_values($this->googleContacts->resoudreNomsGroupes($service, $resourceNamesGroupes));
        $statutContact = null;
        foreach ($nomsGroupes as $nomGroupe) {
            if (in_array($nomGroupe, Famille::ETATS_SELECTIONNABLES, true)) {
                $statutContact = $nomGroupe;
                break;
            }
        }

        return [
            'nom' => $noms?->getFamilyName(),
            'prenom' => $noms?->getMiddleName(),
            'telephone' => $valeurIndex($telephones, 0),
            'telephone_bis' => $valeurIndex($telephones, 1),
            'email' => $valeurIndex($emails, 0),
            'adresse' => $adresse?->getStreetAddress(),
            'code_postal' => $adresse?->getPostalCode(),
            'ville_texte' => $adresse?->getCity(),
            'etudiant' => $etudiant,
            'est_hotel' => $hotel,
            'etat_dossier' => $statutContact,
        ];
    }

    /**
     * Compare famille (DB) et données contact, champ par champ — ne
     * remonte un écart que si la valeur côté contact est non vide ET
     * différente de la valeur DB normalisée (même garde-fou que
     * detectChanges() côté GAS : un champ vide côté Google n'est jamais
     * considéré comme un écart, on ne veut pas qu'un contact incomplet
     * efface une donnée DB déjà renseignée).
     */
    private function comparer(Famille $famille, array $donneesContact): array
    {
        $ecarts = [];

        // Même décomposition que GoogleContactsService::buildPerson() côté
        // écriture : pour un dossier ancien où seul le champ libre
        // `adresse` est renseigné (code_postal/ville_texte vides), le
        // contact Google reçoit une adresse déjà éclatée (rue seule dans
        // streetAddress) — comparer valeurDb=adresse (texte complet) au
        // streetAddress du contact (rue seule) ferait remonter un écart
        // permanent et sans intérêt. On compare donc à la même valeur
        // décomposée que celle réellement envoyée à Google.
        $adresseDecomposee = $this->googleContacts->decomposerAdresse($famille);
        $valeursDbParChamp = [
            'adresse' => $adresseDecomposee['rue'],
            'code_postal' => $adresseDecomposee['code_postal'],
            'ville_texte' => $adresseDecomposee['ville'],
        ];

        foreach ($this->definitionsChamps() as $champ => $definition) {
            $valeurDb = $valeursDbParChamp[$champ] ?? $famille->{$champ};
            $valeurContact = $donneesContact[$champ] ?? null;

            if ($valeurContact === null || $valeurContact === '') {
                continue;
            }

            $normaliser = $definition['normaliser'] ?? null;
            $dbNormalisee = $normaliser ? $normaliser($valeurDb) : $valeurDb;
            $contactNormalisee = $normaliser ? $normaliser($valeurContact) : $valeurContact;

            if ($definition['type'] === 'booleen') {
                $dbNormalisee = (bool) $dbNormalisee;
                $contactNormalisee = (bool) $contactNormalisee;
            }

            if ($dbNormalisee === $contactNormalisee) {
                continue;
            }

            $ecarts[] = [
                'champ' => $champ,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'valeur_db' => $valeurDb,
                'valeur_contact' => $valeurContact,
            ];
        }

        return $ecarts;
    }

    /**
     * Applique les décisions prises par le staff dans le panneau de
     * résolution — pendant de applyContactSyncDecisions() côté GAS.
     *
     * $decisions attendu (voir GoogleContactsReverseSyncController::apply) :
     * [
     *   [
     *     'id_famille' => int,
     *     'champs' => [
     *       ['champ' => 'telephone', 'action' => 'accepter_db'|'accepter_contact'|'ecraser', 'valeur' => string|null],
     *       ...
     *     ],
     *   ],
     *   ...
     * ]
     *
     * Sémantique par action, par champ :
     *   - accepter_db      : la DB garde sa valeur actuelle (rien à
     *                        écrire côté DB) — mais le contact Google est
     *                        repoussé à la fin pour corriger la dérive.
     *   - accepter_contact : la valeur lue côté contact est écrite en DB.
     *   - ecraser          : la valeur tapée par l'admin ('valeur') est
     *                        écrite en DB — et donc aussi repoussée vers
     *                        Google à la fin, comme les deux autres cas.
     *
     * Dans les trois cas, GoogleContactsService::updateContact() est
     * rappelé une fois toutes les décisions appliquées en DB : il repousse
     * l'état FINAL de la famille (donc la valeur DB, quelle qu'ait été la
     * décision) vers Google — ce qui, par construction, résout aussi
     * "accepter_db" (le contact dérivé est corrigé) sans traitement
     * spécial, et laisse "accepter_contact" inchangé côté Google (la valeur
     * repoussée est celle qu'on vient de copier depuis le contact).
     */
    public function appliquer(array $decisions): array
    {
        $resultats = [];

        foreach ($decisions as $decisionFamille) {
            $idFamille = (int) $decisionFamille['id_famille'];
            $famille = Famille::find($idFamille);

            if (!$famille) {
                $resultats[] = ['id_famille' => $idFamille, 'succes' => false, 'erreur' => 'Famille introuvable.'];
                continue;
            }

            $avant = $famille->toArray();
            $aModifie = false;

            foreach ($decisionFamille['champs'] as $champDecision) {
                $champ = $champDecision['champ'];
                $action = $champDecision['action'];

                if (!array_key_exists($champ, $this->definitionsChamps())) {
                    continue; // Champ inconnu/non comparé — ignoré plutôt que de planter tout le lot.
                }

                if ($action === 'accepter_db') {
                    continue; // Rien à changer côté DB.
                }

                $nouvelleValeur = $action === 'ecraser'
                    ? ($champDecision['valeur'] ?? null)
                    : ($champDecision['valeur_contact'] ?? null);

                if ($champ === 'etat_dossier' && !in_array($nouvelleValeur, Famille::ETATS_SELECTIONNABLES, true)) {
                    continue; // Valeur de statut invalide — ignorée plutôt que de mettre la famille dans un état incohérent.
                }

                if (in_array($champ, ['etudiant', 'est_hotel'], true)) {
                    $nouvelleValeur = $this->versBooleen($nouvelleValeur);
                }

                $famille->{$champ} = $nouvelleValeur;
                $aModifie = true;
            }

            if ($aModifie) {
                $famille->save();
                audit('update', 'familles', $famille->id, $avant, $famille->toArray());
            }

            try {
                $this->googleContacts->updateContact($famille->fresh());
                $resultats[] = ['id_famille' => $famille->id, 'succes' => true];
            } catch (\Throwable $e) {
                Log::error('[ReverseSyncService] Échec repoussée contact Google après application des décisions', [
                    'id_famille' => $famille->id,
                    'erreur' => $e->getMessage(),
                ]);
                $resultats[] = ['id_famille' => $famille->id, 'succes' => false, 'erreur' => $e->getMessage()];
            }
        }

        return $resultats;
    }

    private function versBooleen(mixed $valeur): bool
    {
        if (is_bool($valeur)) {
            return $valeur;
        }

        $str = strtolower(trim((string) $valeur));

        return in_array($str, ['oui', 'yes', '1', 'true'], true);
    }
}
