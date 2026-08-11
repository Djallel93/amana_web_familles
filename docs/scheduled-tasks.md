# Tâches planifiées (IONOS cron)

Toutes les commandes ci-dessous passent par `Schedule::` (voir
`routes/console.php`), donc par le scheduler Laravel — ce qui suppose que
le cron **IONOS** suivant est bien configuré côté hébergement :

```
* * * * * php artisan schedule:run >> /dev/null 2>&1
```

**Confirmé actif sur IONOS depuis le 11/08/2026.**

## Commandes actuellement planifiées

| Commande | Fréquence | Rôle |
|---|---|---|
| `familles:nettoyer-demandes-attente` | quotidienne (`->daily()`) | Supprime les demandes du formulaire public non confirmées après 48h (voir `IntakeAttenteService`) ainsi que leurs fichiers temporaires sous `storage/app/private/intake-attente/`. Activée par défaut depuis l'ajout de la confirmation par email (11/08/2026) — évite une fuite d'espace disque silencieuse. |

## Commandes disponibles mais désactivées

| Commande | Fréquence proposée | Pourquoi désactivée |
|---|---|---|
| `familles:envoyer-verifications` | mensuelle (`->monthlyOn(1, '08:00')`) | Envoi automatique des emails de vérification périodique (décision 6.10). Laissée commentée dans `routes/console.php` — le cron IONOS étant maintenant confirmé actif, elle peut être décommentée dès que souhaité (pas de blocage technique restant, juste une décision produit à prendre : envoi automatique vs déclenchement manuel via le bouton `admin.verifications.envoyer`). |

## À faire si une nouvelle commande planifiée est ajoutée

Ajouter une ligne dans le tableau "Commandes actuellement planifiées"
ci-dessus — c'est le seul endroit du repo qui recense ce qui tourne
réellement en tâche de fond, `routes/console.php` seul ne suffit pas à s'en
souvenir en un coup d'œil.
