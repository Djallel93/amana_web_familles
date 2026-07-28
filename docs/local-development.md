# Développement local avec des copies locales de amana/shared et @amana/shared-ui

Pour travailler sur `amana_shared`/`amana_shared_ui` et voir les changements
reflétés immédiatement dans cette app, sans passer par un tag git à chaque
fois.

Suppose le layout de dossiers frères suivant :

```
amana/
├── amana_shared/
├── amana_shared_ui/
└── amana_web_familles/      ← ce dépôt
```

## Composer (amana/shared)

```bash
cp composer.local.json.dist composer.local.json
composer install
```

`composer.local.json` est dans `.gitignore` — jamais commité, rien à annuler
avant de pousser sur `main`. `composer.json` (le fichier commité) continue de
pointer vers le dépôt git privé taggé, inchangé.

Fonctionnement : le plugin `wikimedia/composer-merge-plugin` (voir
`extra.merge-plugin` dans `composer.json`) fusionne `composer.local.json`
dans la configuration Composer *si le fichier existe*. Il ajoute un
repository `path` vers `../amana_shared` et remplace la contrainte de
version `^1.0` par `@dev` (nécessaire : un repository path sans tag
git s'expose à Composer comme une version `dev-*`, que `^1.0` seul ne
satisferait pas). `options.symlink: true` fait que `vendor/amana/shared`
est un symlink vers `../amana_shared` — éditez un fichier là-bas, c'est
immédiatement pris en compte, sans réinstaller.

En CI/production, `composer.local.json` n'existe pas → le plugin ne trouve
rien à fusionner → `composer.json` s'applique normalement (dépôt git
taggé). Aucune action à faire pour "revenir en arrière".

Pour repasser en mode normal (retester contre le dépôt git réel) :
supprimez `composer.local.json` et relancez `composer install`.

## npm (@amana/shared-ui)

Pas besoin d'un mécanisme équivalent au merge-plugin ici : `node_modules/`
est déjà dans `.gitignore`, donc `npm link` ne touche jamais un fichier
commité.

```bash
cd ../amana_shared_ui && npm link
cd ../amana_web_familles && npm link @amana/shared-ui
npm run dev
```

Ceci remplace `node_modules/@amana/shared-ui` par un symlink vers
`../amana_shared_ui` — `package.json` (qui référence le dépôt git privé)
reste inchangé et committable sans risque.

**Piège connu** : un `npm install` ultérieur peut réinstaller la version git
par-dessus le lien symbolique. Si `@amana/shared-ui` semble ne plus refléter
vos changements locaux après un `npm install`, relancez simplement
`npm link @amana/shared-ui`.

Pour revenir en mode normal : `npm unlink @amana/shared-ui && npm install`.
