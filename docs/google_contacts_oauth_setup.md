# Configuration OAuth Google Contacts — `amana_web_familles`

Ce guide explique comment obtenir les 4 variables utilisées par
`GoogleContactsService` / `Admin\GoogleContactsController` :

```dotenv
GOOGLE_CONTACTS_CLIENT_ID
GOOGLE_CONTACTS_CLIENT_SECRET
GOOGLE_CONTACTS_REDIRECT_URI
GOOGLE_CONTACTS_ACCOUNT_EMAIL
```

Il répond aussi à la question : peut-on remplacer tout ça par un
`GOOGLE_SERVICE_ACCOUNT_JSON_BASE64` ? → **non, pas pour ce compte-là** (voir
dernière section — la réponse n'est pas un simple choix de format).

---

## Contexte : pourquoi OAuth et pas juste une clé API

Contrairement à la Geocoding API (clé API projet, déjà en place), les
contacts People API appartiennent à un **compte Google précis**
(`amana44.pole.social@gmail.com`), pas au projet GCP lui-même. Accéder aux
contacts de ce compte nécessite donc que ce compte **consente explicitement**
via OAuth 2.0 — d'où le flux `/admin/google-contacts/authorize` →
`/admin/google-contacts/callback` déjà en place dans le code.

---

## 1. Activer l'API People sur le projet GCP AMANA

1. [Google Cloud Console](https://console.cloud.google.com/) → sélectionner
   le projet GCP AMANA (le même déjà utilisé pour Geocoding/Calendar).
2. **API et services → Bibliothèque** → rechercher **"Google People API"**
   → **Activer**.

## 2. Configurer l'écran de consentement OAuth (si pas déjà fait)

### API et services → Écran de consentement OAuth

- **Type d'utilisateur** : **Externe** — `amana44.pole.social@gmail.com` est
  un compte Gmail grand public, pas un compte Google Workspace géré par
  vous, donc le type "Interne" (réservé aux organisations Workspace)
  n'est pas disponible.
- Renseigner nom de l'app, email de support, logo (facultatif).
- **Scopes** : ajouter `https://www.googleapis.com/auth/contacts`
  (`PeopleService::CONTACTS`, utilisé par `GoogleContactsService`).
- **Utilisateurs test** (section _Test users_) : **ajouter
  `amana44.pole.social@gmail.com`**. C'est l'étape la plus facile à
  oublier — tant que l'app n'est pas passée en "Production" (validation
  Google, généralement inutile ici vu l'usage interne), **seuls les comptes
  listés comme utilisateurs test peuvent terminer le flux de consentement**.
  Sans ça, l'autorisation échouera avec une erreur "app non vérifiée /
  accès refusé", même avec des identifiants OAuth valides.

## 3. Créer l'ID client OAuth 2.0

**API et services → Identifiants → + Créer des identifiants → ID client
OAuth**

- **Type d'application** : **Application Web** (pas "Application de bureau"
  — celui-ci ne permet pas de déclarer un `redirect_uri` HTTPS applicatif).
- **URI de redirection autorisés** — ajouter exactement :

  ```txt
  https://<votre-domaine>/admin/google-contacts/callback
  ```

  (route confirmée dans `routes/web.php` : groupe `admin.` + préfixe
  `/google-contacts` + route `callback`). Doit correspondre **au
  caractère près** à `GOOGLE_CONTACTS_REDIRECT_URI` en `.env` — un
  trailing slash ou http vs https en trop suffit à faire échouer l'échange
  du code d'autorisation.

- Valider → Google affiche **Client ID** et **Client Secret**.

### → Les 4 variables

```dotenv
GOOGLE_CONTACTS_CLIENT_ID="<Client ID généré à l'étape 3>"
GOOGLE_CONTACTS_CLIENT_SECRET="<Client Secret généré à l'étape 3>"
GOOGLE_CONTACTS_REDIRECT_URI="https://<votre-domaine>/admin/google-contacts/callback"
GOOGLE_CONTACTS_ACCOUNT_EMAIL="amana44.pole.social@gmail.com"
```

`GOOGLE_CONTACTS_ACCOUNT_EMAIL` n'est **pas utilisée pour l'authentification**
— purement informative pour l'affichage/les logs (voir
`GoogleContactsService::isConfigured()` et le commentaire dans
`config/services.php`). Le compte réellement autorisé est déterminé par la
session Google active dans le navigateur au moment du consentement, pas par
cette valeur.

## 3bis. Tester en local (sans attendre la mise en prod)

Vous avez raison que `https://familles.amana44.fr/admin/google-contacts/callback`
ne fonctionnera qu'une fois ce domaine réellement joignable — mais rien
n'oblige à n'avoir **qu'une seule** URI de redirection enregistrée sur l'ID
client OAuth. Deux options :

### Option A — exception localhost de Google (la plus simple)

Google autorise explicitement `http://localhost` (et `http://127.0.0.1`) en
HTTP simple, sans HTTPS, comme URI de redirection — seule exception à
l'obligation HTTPS habituelle. Donc :

1. Dans le même ID client OAuth (celui de l'étape 3), **ajouter une
   deuxième URI de redirection autorisée** en plus de celle de prod :

   ```txt
   http://localhost:8000/admin/google-contacts/callback
   ```

   (adapter le port à celui de `php artisan serve`, ou celui de
   Herd/Valet si vous l'utilisez — mais Valet utilise généralement un
   domaine `.test`, pas littéralement `localhost` : dans ce cas, passez par
   l'option B, l'exception Google ne couvre que `localhost`/`127.0.0.1`
   au sens strict, pas un domaine `.test`).

2. En local, `.env` :

   ```dotenv
   GOOGLE_CONTACTS_REDIRECT_URI="http://localhost:8000/admin/google-contacts/callback"
   ```

3. Le reste du flux (`/admin/google-contacts/authorize`, consentement,
   callback, stockage du refresh token) fonctionne alors identiquement en
   local.

Un même `client_id`/`client_secret` peut donc servir en local **et** en
prod — seule l'URI de redirection change selon l'environnement, chacune
enregistrée côté Google Cloud Console.

**Option B — tunnel HTTPS (ngrok, Cloudflare Tunnel) si vous utilisez un
domaine `.test`/Valet, ou pour tester dans des conditions plus proches de
la prod**

1. Lancer un tunnel vers votre serveur local, ex. `ngrok http 8000` →
   Ngrok fournit une URL HTTPS publique temporaire
   (`https://xxxx.ngrok-free.app`).
2. Ajouter `https://xxxx.ngrok-free.app/admin/google-contacts/callback`
   comme URI de redirection supplémentaire sur l'ID client OAuth.
3. `.env` local : `GOOGLE_CONTACTS_REDIRECT_URI` pointant vers cette URL
   ngrok.
4. Inconvénient : l'URL change à chaque redémarrage du tunnel (sauf plan
   payant ngrok avec domaine fixe) — à ré-ajouter côté Google Cloud
   Console à chaque fois. L'option A est donc plus pratique au quotidien
   si `php artisan serve` (littéralement `localhost`) vous convient.

Dans les deux cas : le compte test (`amana44.pole.social@gmail.com`, déjà
ajouté à l'étape 2) fonctionne pareil quelle que soit l'URI de
redirection — c'est une propriété de l'écran de consentement, pas de
l'environnement.

## 4. Lancer le flux d'autorisation (une fois les 4 variables en `.env`)

1. Se connecter à l'admin AMANA.
2. Dans le même navigateur, être connecté à **`amana44.pole.social@gmail.com`**
   côté Google (pas un autre compte Google actif en session).
3. Visiter `/admin/google-contacts/authorize` → redirection vers l'écran de
   consentement Google → accepter.
4. Callback → `Setting::setEncrypted()` stocke le refresh token chiffré en
   base (`ref_settings`) — rien à faire côté `.env` après ça.

Si le message _"Google n'a pas renvoyé de refresh token"_ apparaît (déjà
géré dans le contrôleur), c'est que ce compte a déjà autorisé cette appli
par le passé dans une session précédente — révoquer l'accès sur
[myaccount.google.com/permissions](https://myaccount.google.com/permissions)
puis relancer.

---

---

## Obtenir `GOOGLE_MAPS_GEOCODING_API_KEY`

Contrairement à Contacts, pas de flux OAuth ici — juste une clé API projet.

1. **Google Cloud Console** → même projet GCP AMANA.
2. **Facturation** → vérifier qu'un compte de facturation est bien lié au
   projet (obligatoire pour la Geocoding API, pas de palier gratuit —
   décision déjà actée). Si ce n'est pas encore fait : **Facturation → Lier
   un compte de facturation**.
3. **API et services → Bibliothèque** → rechercher **"Geocoding API"**
   (sous "Maps Platform") → **Activer**.
4. **API et services → Identifiants → + Créer des identifiants → Clé API**
   → Google génère immédiatement une clé brute (ex.
   `AIzaSy...`) — c'est votre `GOOGLE_MAPS_GEOCODING_API_KEY`.
5. **Restreindre la clé** (fortement recommandé — comme évoqué
   précédemment) → cliquer sur la clé nouvellement créée :
   - **Restrictions relatives aux API** → "Restreindre la clé" →
     sélectionner **uniquement "Geocoding API"**. Sans ça, une clé qui
     fuiterait pourrait être utilisée sur n'importe quelle API Maps
     Platform du projet (Places, Directions, Maps JavaScript...), avec
     un impact facturation bien plus large qu'un simple géocodage.
   - **Restrictions relatives aux applications** → **Adresses IP** →
     ajouter l'IP sortante fixe de votre serveur applicatif de
     production. L'appel étant fait exclusivement côté serveur
     (`GoogleGeocodingService`, jamais exposé au frontend), c'est la
     restriction la plus adaptée (par opposition à "Référents HTTP",
     pertinent seulement pour un usage côté navigateur).

**Pour le test en local** : votre IP domestique/bureau change
probablement (IP dynamique), donc la contraindre précisément est peu
pratique au quotidien. Deux approches raisonnables :

- **La plus simple** : ne mettre **que** la restriction "API" (Geocoding
  uniquement) sur la clé utilisée en local/dev, sans restriction IP — la
  clé reste cantonnée au géocodage (donc à un coût plafonné et prévisible)
  même sans restriction IP. Ne l'utilisez pas telle quelle en prod.
- **Plus rigoureux** : créer **deux clés distinctes** dans le même
  projet — une pour le dev (restreinte par API seulement, ou par votre IP
  du moment si elle est stable), une pour la prod (restreinte par API **et**
  IP du serveur). Chacune dans le `.env` de son environnement respectif.
  C'est aussi ce qui permet de révoquer/régénérer la clé de dev sans
  impacter la prod si elle venait à fuiter (ex. commit accidentel).

Test rapide une fois la clé en `.env` local :

```
php artisan familles:tester-geocodage --adresse="1 rue de la Paix" --code-postal=44000 --ville=Nantes
```

---

## Et un `GOOGLE_SERVICE_ACCOUNT_JSON_BASE64` à la place ?

**Non, pas pour ce compte-là, et ce n'est pas qu'une question de format de
credentials.** C'est une limite structurelle de Google, pas de choix
d'implémentation :

- Un compte de service (_service account_) est une **identité
  d'application**, pas un utilisateur. Il a sa propre boîte de contacts
  People API (vide, inutile ici), distincte de celle d'un vrai compte
  Gmail.
- Pour qu'un compte de service accède aux données personnelles d'un
  **vrai** utilisateur (ses contacts, son Drive, son Calendar...), Google
  exige la **délégation à l'échelle du domaine** ("domain-wide delegation")
  — le compte de service _emprunte l'identité_ de l'utilisateur cible.
- **Cette fonctionnalité n'existe que pour Google Workspace** (l'ancien G
  Suite, payant, avec un domaine géré et une console d'administration).
  Elle nécessite qu'un **super-admin Workspace** autorise explicitement le
  compte de service dans **Admin Console → Sécurité → Contrôle des API →
  Délégation au niveau du domaine**.
- `amana44.pole.social@gmail.com` est un **compte Gmail grand public**
  (pas Workspace) — il n'existe pas d'Admin Console pour ce type de compte,
  donc **la délégation de domaine est tout simplement impossible** sur ce
  compte. Aucune configuration de compte de service ne peut contourner ça :
  Google refusera systématiquement l'accès (`unauthorized_client` /
  `insufficient permission`) quel que soit le JSON fourni.

**Résumé de la différence avec la Geocoding API**, où une clé API a
suffi : la Geocoding API est une API **au niveau du projet GCP**, sans
notion de "propriétaire" des données — une clé API projet ou un compte de
service conviennent tous les deux. People API accède, elle, aux données
**personnelles d'un compte précis** — elle exige donc soit un consentement
OAuth explicite de ce compte (ce qui est déjà implémenté), soit une
délégation de domaine (impossible sur un compte Gmail grand public).

### Si vous migriez vers un compte Google Workspace pour AMANA

Ce serait alors possible — mais ce n'est pas juste ajouter une variable
`.env` : cela demanderait de refaire `GoogleContactsService` pour utiliser
`Google\Client::setSubject($email)` avec un compte de service + délégation
de domaine, en plus de la migration du compte lui-même
(`amana44.pole.social@gmail.com` → un compte `@amana.fr` ou équivalent
Workspace) et de la configuration côté Admin Console. Un changement de
portée bien plus large que ce ticket — à traiter séparément si vous
souhaitez l'envisager.
