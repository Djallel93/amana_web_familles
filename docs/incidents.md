# Incidents — types, détection automatique et aide à la résolution

Les incidents (`RouteIncident`) vivent dans la section **Incidents** du hub d'une campagne
(`Livraison → Campagnes → <campagne>`). Chacun a un type, un statut (`ouvert`, `ignore`, `resolu`),
une description, un **guide de résolution pas à pas** et, selon le type, des **actions directes**.
Une alerte (cloche + toast + bandeau urgent) part vers les admins et gestionnaires à la création.

Ce document est la référence : à lire avant d'ajouter un type.

## Les 21 types

### Levés par un geste humain (historiques)

| Type | Levé par | Actions de résolution |
|---|---|---|
| `benevole_absent` | Chargement : chauffeur absent | Résoudre → re-clustering des arrêts restants ; si des familles restent sans tournée, un incident `livraisons_non_couvertes` est ouvert |
| `capacite` | Chargement/Ma route, **et automatiquement** (voir plus bas) | Retirer des familles · scinder la tournée · confier à un véhicule plus grand |
| `chargement_termine` | Confirmation du chargement | Aucun : jalon (statut `null`), jamais listé |
| `livraison_ignoree` | Chauffeur : arrêt ignoré | Remettre à planifier · tournée existante · retrait QG · imposer à un chauffeur |
| `packaging_annule` | Packaging : colis annulé | Se résout seul quand le colis est de nouveau prêt |
| `chargement_annule` | Chargement : annulation | **Se résout seul** quand la tournée est reconfirmée |
| `retrait_hq_non_livre` | Retrait QG : « Non livré (absent) » | La famille peut revenir · la livrer à domicile |
| `se_deplace_remplace` | Chauffeur imposé à une famille du QG | Famille prévenue · annuler la livraison imposée |
| `chauffeur_indisponible` | Chauffeur répond « Non » au retour au QG | Réassigner · répartir · remettre dans le pool |

### Détectés automatiquement (23/10/2026)

`RouteIncident::TYPES_DETECTES`, levés avec `signale_par = 0` par `IncidentDetectionService`.

| Type | Condition | Seuil (`config/incidents.php`) | Actions de résolution |
|---|---|---|---|
| `famille_non_geocodee` | Famille confirmée à servir sans latitude/longitude | — | Relancer la localisation |
| `livraisons_non_couvertes` | Des tournées existent et des familles à domicile n'ont aucune tournée (coordonnées connues, ni QG, ni chauffeur imposé) | — | Les faire venir au QG |
| `famille_injoignable` | Statut de contact « injoignable » depuis trop longtemps | `INCIDENT_INJOIGNABLE_DELAI_HEURES` (24 h) | Remettre à contacter · retirer de la campagne |
| `tournee_non_demarree` | Tournée « chargée » et créneau commencé depuis X min | `INCIDENT_RETARD_DEMARRAGE_MINUTES` (30) | Guide seul (appeler le chauffeur) |
| `packaging_en_retard` | Colis non prêts à X min du début du créneau | `INCIDENT_ANTICIPATION_PACKAGING_MINUTES` (30) | Guide seul |
| `chargement_en_retard` | Colis prêts, chargement non confirmé X min après le début du créneau | `INCIDENT_RETARD_CHARGEMENT_MINUTES` (0) | Guide seul |
| `retrait_hq_en_retard` | Rendez-vous au QG dépassé de X min, famille ni livrée ni « non livré » | `INCIDENT_RETARD_RETRAIT_HQ_MINUTES` (30) | Guide seul |
| `collecte_insuffisante` | Collecte commencée mais couverture < 100 % | — | Guide seul (voir écran Packaging) |
| `chauffeur_retire` | Chauffeur d'une tournée pas partie qui n'est plus confirmé pour la journée | — | Guide seul |
| `chauffeurs_insuffisants` | Aucun chauffeur confirmé, ou plus de X familles par chauffeur sur une journée | `INCIDENT_FAMILLES_MAX_PAR_CHAUFFEUR` (25) | Guide seul |
| `email_echec` | Un email de retrait QG ou de confirmation n'a pas pu partir | — | Renvoyer l'email |
| `job_echec` | `ResoudreAdresseFamille` ou `SynchroniserContactGoogle` a échoué définitivement | — | Guide seul |

Le type `capacite` est aussi levé automatiquement (poids de la tournée > capacité de son véhicule).

## Comment fonctionne la détection

- `php artisan livraison:detecter-incidents [--campagne=ID]`, planifiée **toutes les 5 minutes** pour les
  campagnes `en_cours` (`routes/console.php`). Le cron `schedule:run` est déjà installé par les workflows de
  déploiement : rien à ajouter dans GitHub Actions.
- **Idempotente** : chaque incident porte une clé `[auto:type:cle]` dans ses notes. Une situation déjà
  signalée n'est jamais dupliquée.
- **Auto-résolution** : quand la situation rentre dans l'ordre (adresse localisée, tournée démarrée…), l'incident
  se ferme avec le marqueur `[auto-resolu]` et sa notification disparaît. Si le problème revient, un nouvel
  incident est ouvert.
- **Une décision humaine est respectée** : un incident que vous avez résolu ou ignoré à la main n'est pas
  recréé. Pour le suivre de nouveau, rouvrez-le.
- `email_echec` et `job_echec` sont levés sur événement (échec d'envoi, `failed()` du job) et ne sont jamais
  auto-résolus : il n'y a rien à re-tester.
- Les seuils se règlent par variables d'environnement (voir `config/incidents.php`).

## Ajouter un type d'incident

Un type vit à **cinq endroits** — `tests/Feature/Models/IncidentTypesTest.php` échoue s'il en manque un :

1. enum `type` de `database/migrations/2026_08_31_000009_create_livraison_routing_tables.php` ;
2. `RouteIncident::TYPES`, `LABELS_TYPE`, `GUIDES` (et `description()`) ;
3. `TYPES_INCIDENT` dans `resources/js/components/livraison/shared/types.ts` ;
4. si détecté automatiquement : `RouteIncident::TYPES_DETECTES` et un détecteur dans
   `IncidentDetectionService` (+ sa ligne dans `TYPES_ETAT` s'il doit être réévalué) ;
5. si une action directe existe : `IncidentResolutionService::resoudreAutre()`, la liste d'actions
   autorisées de `IncidentsController::resoudreAvecSuite()` et `CHOIX_PAR_TYPE` de `CampagneIncidents.vue`.

## Signal visuel d'un nouvel incident

La cloche (`@amana/shared-ui`, `NotificationBell`) interroge `/notifications` toutes les 20 s : un nouvel
incident déclenche un **toast** (rouge 10 s si urgent), une **animation de la cloche**, un badge qui pulse et
le compteur dans le titre de l'onglet. Voir le README de `amana_shared_ui`.
