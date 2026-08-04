# Google Contacts (People API) integration — changed/new files

Two repos are affected, each in its own top-level folder in this zip,
mirroring the exact paths in your local checkouts.

## amana_web_planning (1 new file)

- `database/migrations/2026_07_17_000001_add_encrypted_type_to_ref_settings.php`
  Widens `ref_settings.valeur` to `TEXT` and adds `'encrypted'` to the
  `type` enum. This lives here (not in familles) because `ref_settings` is
  owned/created by this repo's migrations — same convention already used by
  `2026_07_12_000001_register_familles_application.php`.

Run `php artisan migrate` in **amana_web_planning** first (or wherever you
normally run shared-table migrations against the `amana` database).

## amana_web_familles

**New files:**

- `database/migrations/2026_07_17_000001_add_google_resource_name_to_familles.php`
- `app/Jobs/SynchroniserContactGoogle.php` — replaces `app/Jobs/EnvoyerWebhookContact.php`
- `app/Services/GoogleContactsService.php`
- `app/Http/Controllers/Admin/GoogleContactsController.php`

**Changed files (full contents, copy over your existing versions):**

- `app/Models/Famille.php` — added `google_resource_name` to `$fillable`
- `app/Models/Setting.php` — added `'encrypted'` type support (`setEncrypted()`, decrypt-on-read)
- `app/Http/Controllers/FamillesController.php` — dispatches `SynchroniserContactGoogle` instead of `EnvoyerWebhookContact`
- `config/services.php` — removed `make.contact_*`, added `google.contacts.*`
- `.env.example` — removed `MAKE_CONTACT_*`, added `GOOGLE_CONTACTS_*`
- `composer.json` — added `google/apiclient: ^2.18`
- `routes/web.php` — added `admin.google-contacts.authorize` / `.callback`

**Delete** `app/Jobs/EnvoyerWebhookContact.php` — it's fully replaced.

## Setup steps (in order)

1. Copy the files into place, then:

    ```bash
    composer require google/apiclient   # or just `composer install` after merging composer.json
    ```

2. In **amana_web_planning**: `php artisan migrate`
3. In **amana_web_familles**: `php artisan migrate`
4. In Google Cloud Console (your existing AMANA project) → APIs & Services →
   Credentials: create (or reuse) an **OAuth 2.0 Client ID — Web application**,
   and add an authorized redirect URI matching exactly what you'll put in
   `GOOGLE_CONTACTS_REDIRECT_URI`, e.g.:
    - Local: `http://familles.test/admin/google-contacts/callback`
    - Prod: `https://<your-familles-domain>/admin/google-contacts/callback`
      Also make sure **People API** is enabled for the project.
5. Set in `.env`:

    ```txt
    GOOGLE_CONTACTS_CLIENT_ID=...
    GOOGLE_CONTACTS_CLIENT_SECRET=...
    GOOGLE_CONTACTS_REDIRECT_URI=http://familles.test/admin/google-contacts/callback
    GOOGLE_CONTACTS_ACCOUNT_EMAIL=amana44.pole.social@gmail.com
    ```

    Remove the old `MAKE_CONTACT_WEBHOOK_URL` / `MAKE_CONTACT_WEBHOOK_APIKEY`
    lines (geocoding's `MAKE_GEOCODING_*` stays untouched).
6. As an admin user, **log into the browser with the
   `amana44.pole.social@gmail.com` Google account**, then visit:
   `/admin/google-contacts/authorize`
   Approve the consent screen (Contacts scope). You'll be redirected back
   with a success flash message once the refresh token is stored (encrypted,
   in `ref_settings`).
7. Validate a test dossier (transition `etat_dossier` → `Validé`) and check
   the queue worker logs / `audit_logs` (module `familles_contact`) to
   confirm the contact was created in Google Contacts on that account.

## Notes

- If step 6 ever needs to be redone (revoked/expired token), first revoke
  existing access at <https://myaccount.google.com/permissions> for that
  Google account, _then_ revisit `/admin/google-contacts/authorize` — Google
  only reliably re-issues a refresh token when there's no prior active grant.
- Geocoding (`ResoudreAdresseFamille`, `MAKE_GEOCODING_*`) is completely
  untouched, as agreed.
