# Tâches planifiées (IONOS cron)

Toutes les commandes ci-dessous passent par `Schedule::` (voir
`routes/console.php`), donc par le scheduler Laravel, qui ne tourne que si
le cron suivant existe sur le compte IONOS :

```
* * * * * cd <IONOS_REMOTE_PATH> && <IONOS_PHP_CLI_PATH> artisan schedule:run >> /dev/null 2>&1 # amana_web_familles-scheduler
```

**Installé automatiquement par les workflows de déploiement** (11/10/2026) —
étape « Ensure Laravel scheduler cron entry (idempotent) » de `deploy.yaml` et
`deploy-preprod.yaml` (marqueurs `amana_web_familles-scheduler` et
`amana_web_familles-preprod-scheduler`). Elle ajoute ou met à jour sa propre ligne
du `crontab` en conservant les autres entrées, et se répare toute seule si le
crontab est vidé ou si `IONOS_REMOTE_PATH` / `IONOS_PHP_CLI_PATH` changent. Si
l'hébergeur refuse `crontab`, l'étape échoue sans casser le déploiement
(`continue-on-error`) : le cron serait alors à créer à la main dans le panneau IONOS.

**Pourquoi `Schedule::exec` et `SCHEDULE_PHP_BINARY`** : `Schedule::command()` relance
chaque commande via le PHP deviné par Symfony, qui sur IONOS est le mauvais
`/usr/bin/php` (exit 255). `routes/console.php` relance donc chaque commande avec
`SCHEDULE_PHP_BINARY`, rendue dans le `.env` depuis `IONOS_PHP_CLI_PATH`, la même
variable que celle du cron.

## Commandes actuellement planifiées

| Commande | Fréquence | Rôle |
|---|---|---|
| `familles:nettoyer-demandes-attente` | quotidienne (`->daily()`) | Supprime les demandes du formulaire public non confirmées après 48h (voir `IntakeAttenteService`) ainsi que leurs fichiers temporaires sous `storage/app/private/intake-attente/`. Activée par défaut depuis l'ajout de la confirmation par email (11/08/2026) — évite une fuite d'espace disque silencieuse. |
| `familles:liberer-verrous-perimes` | toutes les 5 minutes (`->everyFiveMinutes()->withoutOverlapping()`) | Libère les verrous d'édition de dossiers périmés (TTL 20 min, voir `Famille::libererVerrousPerimes()`). |
| `livraison:detecter-incidents` | toutes les 5 minutes (`->everyFiveMinutes()->withoutOverlapping()`) | Détecte les incidents évitables des campagnes **en cours** (famille non localisée, tournée qui ne démarre pas, packaging en retard, collecte insuffisante…) et referme ceux dont le problème a disparu — voir `docs/incidents.md`. Aucun workflow à modifier : le cron `schedule:run` des workflows de déploiement la lance déjà. |

## Commandes disponibles mais désactivées

| Commande | Fréquence proposée | Pourquoi désactivée |
|---|---|---|
| `familles:envoyer-verifications` | mensuelle (`->monthlyOn(1, '08:00')`) | Envoi automatique des emails de vérification périodique (décision 6.10). Laissée commentée dans `routes/console.php` — le cron étant installé par le déploiement, elle peut être décommentée dès que souhaité (pas de blocage technique restant, juste une décision produit à prendre : envoi automatique vs déclenchement manuel via le bouton `admin.verifications.envoyer`). |

## À faire si une nouvelle commande planifiée est ajoutée

Ajouter une ligne dans le tableau "Commandes actuellement planifiées"
ci-dessus — c'est le seul endroit du repo qui recense ce qui tourne
réellement en tâche de fond, `routes/console.php` seul ne suffit pas à s'en
souvenir en un coup d'œil.
