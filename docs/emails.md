# Emails — comment les déclencher et comment les modifier

Ce guide sert à **retoucher un email pas à pas** jusqu'au résultat voulu : on modifie le gabarit,
on relance une commande, on regarde le rendu. Rien n'est écrit en base de données : l'outil
fabrique des données d'exemple en mémoire.

- [1. La boucle de mise au point](#1-la-boucle-de-mise-au-point)
- [2. La commande `familles:apercu-email`](#2-la-commande-famillesapercu-email)
- [3. Voir l'email dans une vraie boîte (Mailpit)](#3-voir-lemail-dans-une-vraie-boîte-mailpit)
- [4. Depuis tinker](#4-depuis-tinker)
- [5. Où modifier quoi](#5-où-modifier-quoi)
- [6. Catalogue : chaque email, son gabarit, son déclencheur réel](#6-catalogue--chaque-email-son-gabarit-son-déclencheur-réel)
- [7. Ajouter un nouvel email](#7-ajouter-un-nouvel-email)
- [8. Pièges connus](#8-pièges-connus)

---

## 1. La boucle de mise au point

```bash
# 1. Voir la liste des emails et leur gabarit
php artisan familles:apercu-email

# 2. Rendre l'email voulu dans un fichier HTML
php artisan familles:apercu-email retrait-hq

# 3. Ouvrir storage/app/email-previews/retrait-hq.fr.html dans le navigateur
# 4. Modifier le gabarit (resources/views/emails/retrait-hq.blade.php)
# 5. Relancer l'étape 2, recharger la page — recommencer jusqu'à satisfaction
# 6. Quand c'est bon : l'envoyer pour de vrai à soi-même (voir §3)
php artisan familles:apercu-email retrait-hq --envoyer --a=moi@exemple.fr
```

En docker : préfixer par `docker compose exec app` (ou `make artisan familles:apercu-email ...`).

## 2. La commande `familles:apercu-email`

| Usage | Effet |
|---|---|
| `familles:apercu-email` | Tableau de tous les emails : clé, gabarit à éditer, langues, destinataire, déclencheur |
| `familles:apercu-email <clé>` | Écrit `storage/app/email-previews/<clé>.<langue>.html` et affiche le sujet |
| `familles:apercu-email <clé> --langue=ar` | Idem dans une autre langue (`fr`, `ar`, `en`) — seuls les emails multilingues changent |
| `familles:apercu-email tous` | Rend tous les emails d'un coup (utile après une retouche du gabarit commun) |
| `familles:apercu-email <clé> --sortie=/chemin` | Autre dossier de sortie |
| `familles:apercu-email <clé> --envoyer --a=adresse` | **Envoie vraiment** l'email à cette adresse |
| `... --envoyer --a=adresse --mailer=smtp` | Force le mailer pour cet envoi |

Le fichier HTML produit est **autonome** : le logo et le QR code, normalement joints à l'email
par `cid:`, sont remplacés par des images intégrées — ce que vous voyez est ce que verra le
destinataire. Si le mailer est `log` ou `array`, l'envoi ne part pas réellement : la commande
vous le dit.

## 3. Voir l'email dans une vraie boîte (Mailpit)

Le rendu HTML ne montre pas tout (objet, en-têtes, comportement des clients mail). En local avec
docker, **Mailpit** attrape tous les emails sortants : interface sur <http://localhost:8025>.

Dans `.env` (déjà le cas dans `.env.docker.example`) :

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

Puis `php artisan familles:apercu-email retrait-hq --envoyer --a=n-importe-quoi@exemple.fr` et
ouvrez Mailpit. Avec `MAIL_MAILER=log` (défaut de `.env.example`), l'email est seulement écrit
en MIME brut dans `storage/logs/laravel.log`.

## 4. Depuis tinker

```php
php artisan tinker

$apercu = app(\App\Support\ApercuEmail\ApercuEmail::class);

$apercu->rendre('retrait-hq', 'ar');                 // ['sujet' => ..., 'html' => ..., 'destinataire' => ...]
$apercu->enregistrer('retrait-hq', 'fr', '/tmp/x');  // écrit /tmp/x/retrait-hq.fr.html
$apercu->envoyer('route-incident', 'moi@exemple.fr'); // envoi réel (canal mail seulement)

app(\App\Support\ApercuEmail\CatalogueEmails::class)->cles();   // toutes les clés
```

Pour tester avec **vos propres données** plutôt que l'exemple : construisez la notification
vous-même, par exemple avec une vraie famille de la base —
`(new \App\Notifications\RetraitHqAnnuleNotification(\App\Models\Famille::find(12)))->toMail($famille)->render()`.

## 5. Où modifier quoi

| Je veux changer… | Fichier |
|---|---|
| Le **texte / la mise en page** d'un email | `resources/views/emails/<gabarit>.blade.php` (voir la colonne « Gabarit » du catalogue) |
| L'**objet** (sujet) | La classe `app/Notifications/<Nom>Notification.php` (constante `SUJETS` pour les emails fr/ar/en, ou `->subject(...)`) |
| Les **données** passées au gabarit | La même classe, méthode `toMail()` (tableau passé à `->view(...)`) |
| Les **textes en arabe / anglais** | Dans le gabarit : bloc `@php $t = [...]` en tête de fichier, une entrée par langue ; l'arabe passe en `dir="rtl"` automatiquement |
| L'**en-tête, le pied de page, la formule de clôture, le hadith** | Gabarits partagés dans le dépôt `amana_shared` : `resources/views/emails/partials/_header`, `_footer`, `_closing`, `_hadith` |
| Les **couleurs** des emails | `config/amana-shared.php`, clé `email_theme` (le thème « familles » est teal) |
| Le **nom de l'application / l'email de contact** dans le pied | `config/amana-shared.php`, clé `branding` |
| Le **logo** | `public/images/amana-logo.png` |
| Les emails de **compte** (mot de passe, changement d'email) | Dépôt `amana_shared` : `resources/views/emails/compte.blade.php` et `src/Notifications/*Notification.php` |
| Une carte réutilisée par plusieurs emails de l'app | `resources/views/emails/partials/_features-card.blade.php` |

Notes :

- Le CSS des emails est en `<style>` dans `_head` (dépôt `amana_shared`) : pas d'inliner, gardez des
  règles simples et compatibles avec les clients mail.
- Les gabarits partagés viennent de `vendor/amana/shared`. Pour tester une retouche **avant** de
  publier une nouvelle version de `amana_shared`, utilisez `composer.local.json` (copie de
  `composer.local.json.dist`) qui pointe vers le dossier frère `../amana_shared` en lien symbolique.
- Trois emails n'ont **pas de gabarit** et utilisent la mise en page Laravel par défaut
  (`route-modifiee`, `demande-nouvelle-tournee`, `packaging-annule` — colonne « Gabarit » :
  « mise en page Laravel par défaut »). Pour les habiller : créer un gabarit
  `resources/views/emails/<nom>.blade.php` sur le modèle de `route-chargee.blade.php`, puis
  remplacer les `->line()/->action()` de la classe par `->view('emails.<nom>', [...])` et
  `'logoCid' => $this->logoCid()`.

## 6. Catalogue : chaque email, son gabarit, son déclencheur réel

« Déclencher pour de vrai » = provoquer l'envoi par l'application elle-même (utile pour un test
de bout en bout) ; pour juste voir le rendu, la commande `familles:apercu-email <clé>` suffit.

### Familles (destinataire sans compte — fr / ar / en)

| Clé | Gabarit | Objet (fr) | Déclencher pour de vrai |
|---|---|---|---|
| `intake-confirmation` | `emails.intake-confirmation` | Merci de confirmer votre demande | Remplir le formulaire public de demande d'aide (`/demande`) avec une adresse email |
| `verification-famille` | `emails.verification-famille` | Merci de vérifier vos informations | Admin → Vérifications → « Envoyer » ; ou `php artisan familles:envoyer-verifications` (familles au dossier validé) |
| `livraison-confirmation` | `emails.livraison-confirmation` | Merci de confirmer votre disponibilité | Démarrer une campagne (`CampagneDemarrageService`) : chaque famille confirmée reçoit son lien |
| `retrait-hq` | `emails.retrait-hq` | Votre créneau de retrait au QG | Une famille « se déplace » dont le créneau QG vient d'être planifié (démarrage de campagne / `RetraitHqSchedulingService`) — contient le QR code |
| `retrait-hq-annule` | `emails.retrait-hq-annule` | Votre retrait au QG est annulé | Passer une famille « se déplace » en livraison à domicile (`LivraisonChangementService`) |

### Bénévoles

| Clé | Gabarit | Destinataire | Déclencher pour de vrai |
|---|---|---|---|
| `benevole-confirmation` | `emails.benevole-confirmation` (fr/ar/en) | Candidat (sans compte) | Remplir le formulaire public de candidature bénévole |
| `nouvelle-candidature-benevole` | `emails.nouvelle-candidature-benevole` | Admins | Le candidat clique le lien de confirmation reçu |
| `benevole-candidature-validee` | `emails.benevole-candidature-validee` | Bénévole (nouveau compte) | Admin → Bénévoles → valider une candidature |
| `benevole-candidature-validee-deja-inscrit` | `emails.benevole-candidature-validee-deja-inscrit` | Bénévole (compte existant) | Idem, pour une personne qui a déjà un compte |
| `campagne-disponibilite` | `emails.campagne-disponibilite` (fr/ar/en) | Bénévoles | Ouvrir une campagne aux disponibilités (`BenevoleDisponibiliteService`) |

### Administration

| Clé | Gabarit | Destinataire | Déclencher pour de vrai |
|---|---|---|---|
| `nouvelle-demande-famille` | `emails.nouvelle-demande-famille` | Staff | La famille clique le lien de confirmation reçu |
| `invitation-familles` | `emails.invitation-familles` | Nouvel utilisateur | Admin → Personnes → donner l'accès à AMANA Familles à quelqu'un sans compte |
| `invitation-familles-deja-inscrit` | `emails.invitation-familles-deja-inscrit` | Utilisateur existant | Idem, pour quelqu'un qui a déjà un compte |

### Livraison

| Clé | Gabarit | Destinataire | Déclencher pour de vrai |
|---|---|---|---|
| `route-chargee` | `emails.route-chargee` | Chauffeur | Équipe chargement : confirmer le chargement d'une tournée |
| `route-prete-a-charger` | `emails.route-prete-a-charger` | Chauffeur | Équipe packaging : conditionner le dernier colis d'une tournée |
| `route-incident` | `emails.route-incident` | Admins / gestionnaires | Créer un incident — manuel (« Bénévole absent » au Chargement, « Non livré » au Retrait QG) ou détecté automatiquement par `livraison:detecter-incidents` (voir `docs/incidents.md`) — les incidents urgents seulement |
| `route-modifiee` | *(Laravel par défaut)* | Chauffeur | Le gestionnaire modifie une tournée déjà chargée/en cours (ajout/retrait d'arrêt) |
| `demande-nouvelle-tournee` | *(Laravel par défaut)* | Gestionnaires | Un chauffeur répond « Oui » à « Revenez-vous au QG ? » (`RetourQgService`) |
| `packaging-annule` | *(Laravel par défaut)* | Équipe chargement | Équipe packaging : reprendre un colis d'une tournée en chargement |

### Comptes (gabarit unique dans `amana_shared` : `amana-shared::emails.compte`)

| Clé | Déclencher pour de vrai |
|---|---|
| `compte-reinitialisation-mot-de-passe` | « Mot de passe oublié » sur la page de connexion, ou création de compte |
| `compte-mot-de-passe-modifie` | Changer son mot de passe (Mon profil / réinitialisation / par un admin) |
| `compte-confirmation-changement-email` | Demander un changement d'adresse email dans Mon profil (envoyé à la **nouvelle** adresse) |
| `compte-email-modifie` | Changement d'email effectué (envoyé à l'**ancienne** adresse) |
| `compte-email-connexion` | Changement d'email effectué (envoyé à la **nouvelle** adresse) |

## 7. Ajouter un nouvel email

1. Créer la notification `app/Notifications/<Nom>Notification.php` (sur le modèle de
   `RetraitHqAnnuleNotification`) et son gabarit `resources/views/emails/<nom>.blade.php`.
2. Ajouter **une entrée** dans `App\Support\ApercuEmail\CatalogueEmails::entrees()` : la classe, le
   gabarit, le type de destinataire (`personne` ou `anonyme`), si l'email est multilingue, le
   déclencheur réel, et une closure qui construit la notification avec des données d'exemple.
3. Lancer `php artisan test --filter=ApercuEmailTest` : le test refuse une notification d'email
   absente du catalogue, et rend chaque entrée pour attraper une faute de gabarit ou de route.
4. Compléter le tableau du §6 de ce guide.

## 8. Pièges connus

- **`Notification::fake()` ne rend jamais l'email.** Une faute de gabarit ou un nom de route faux
  ne se voit qu'à un vrai rendu (c'est ainsi qu'un nom de route erroné a fait échouer en silence
  les emails de retrait QG). Les tests de `ApercuEmailTest` rendent vraiment chaque email.
- **Les emails d'erreur d'envoi sont seulement journalisés** (`storage/logs/laravel.log`). Un email
  qui « ne part pas » : chercher `[…Notification]` dans les logs.
- **`MAIL_MAILER=log`** (défaut hors docker) n'envoie rien.
- **Arabe** : le gabarit doit poser `dir="rtl"` ; vérifier le rendu avec `--langue=ar`, pas
  seulement en français.
- **Images** : le logo et le QR sont joints par `cid:` à l'envoi réel ; certains clients mail
  masquent les images tant qu'on n'a pas cliqué « afficher les images ».
