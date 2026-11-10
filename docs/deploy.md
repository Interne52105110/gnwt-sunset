# Deploying the public demo on shared hosting (no shell)

The demo runs on an OVHcloud shared hosting plan: Apache, PHP 8.5, MySQL 8.4, plain FTP (no TLS, no SFTP) and no SSH shell, so no Composer or Drush on the server. Everything that needs a shell happens in CI or locally, and code reaches the server over HTTPS, not FTP.

## First installation

### Build

```bash
cd drupal && vendor/bin/drush sunset:package data/sunset-package.jsonl.gz   # inventory data
cd .. && bash deploy/build.sh                                                # release archive
```

`deploy/build.sh` installs production dependencies only (`composer install --no-dev --optimize-autoloader`), adds the production `settings.php` (no secrets: the installer appends the database credentials on the server), the `.ovhconfig` that selects PHP 8.5 for the site folder, the data package, and writes a one-time extractor whose file name carries a random key.

### Upload and unpack

1. In the hosting panel, attach the domain to the folder `sunset/web` and create a MySQL database.
2. Upload `build/upload/sunset` by FTP to the hosting home folder: the archive lands in `sunset/`, the extractor in `sunset/web/`.
3. Open `https://<domain>/extract-<key>.php?k=<key>`: it unpacks the archive in 20-second slices, then deletes the archive and itself.

### Install and load data

1. Open `/core/install.php`, choose the **Sunset toolkit demo** profile, enter the database credentials and the administrator account. The profile sets the themes, the front page, closes registration and opens the demo pages to visitors.
2. As administrator, open *Configuration » Search and metadata » Legacy site sunset toolkit » Import data* and run the import: the Batch API loads the 57,000 rows in slices that stay under the hosting's execution time limit.

## Continuous deployment

`.github/workflows/ci.yml` tests every push; on `main`, the `deploy` job then ships the release. Files involved:

| File | Role |
| --- | --- |
| `deploy/build-dist.sh` | Builds the tree that lives in the hosting folder (production dependencies, custom code, patches, `.ovhconfig`) and writes the receiver with the public key. |
| `deploy/push.php` | Runs in CI. Compares checksums with the server's manifest, sends the changed files in 4 MB parts, then drives the receiver step by step. |
| `deploy/receiver.php` | Lives on the server as `web/_deploy.php`. Checks each request's Ed25519 signature (body hash, time within 5 minutes, host name), then: extract in 20-second slices, delete removed files and `deploy/obsolete-paths.txt`, rebuild like `core/rebuild.php`, run database updates like `drush updatedb`, record the manifest. Never touches `web/sites` (settings, uploads). |
| `deploy/smoke-test.php` | Checks the live site after each deployment and every day (`monitor.yml`). |
| `deploy/keygen.php` | Creates the key pair: the private key goes straight into the `DEPLOY_PRIVATE_KEY` GitHub secret through `gh`, never printed or saved; the public key goes to `deploy/deploy-key.pub`. |

Why not FTP from CI: the host's FTP has no TLS, so the account password would cross the internet in clear on every run, and it opens every site of the hosting account. Why not the host's own Git integration: it needs an empty folder, says nothing about files it does not track (`settings.php`, uploads), and cannot run Drupal updates.

**Setting it up** (once): run `php deploy/keygen.php <owner>/<repo>`, commit `deploy/deploy-key.pub`, build once with `deploy/build-dist.sh` and upload `build/dist/web/_deploy.php` to `sunset/web/` by FTP. From then on, pushing to `main` deploys. After rotating the key, upload the new receiver the same way.

**Manual run:** *Actions » CI/CD » Run workflow*, with *force* to rebuild caches and run updates when no file changed.

**First pipeline run** on a site installed by hand: the server has no manifest yet, so every file is sent once (27,000 files, 38 MB, about a minute).

## Lessons from the first deployment (OVHcloud, November 2026)

- **OPcache API restricted.** The host sets `opcache.restrict_api` to its own script, so every `opcache_invalidate()` raises a warning. Drupal 11.4's hook collector calls it for each PHP file it reads, and the installer prints every warning, which breaks its session. `deploy/patches/opcache-restrict-api.patch` silences that one call; `deploy/build.sh` applies every patch in that folder.
- **No `user.module` in `hook_install()` of a profile.** Use the entity API (`Role::load()->grantPermission()`), not `user_role_grant_permissions()`.
- **One container per environment.** Drupal 11.4 checks theme access against a list compiled into the service container; `drush cr` only rebuilds the CLI container. After installing a theme from the command line, clear the `cache_container` table or rebuild from the web (`/update.php` does it).
- **Batch pages in a background tab.** Browsers throttle a hidden tab, so the batch progress page may stall; the batch itself is safe and resumes when the page is visible again.
- **Deploying an update by hand** (before the pipeline): upload the changed folders, then run `/update.php`. The pipeline now does both.
- **Warm the pages after an update.** A cache rebuild makes Drupal regenerate its CSS and JS aggregates on the next requests; a page loaded meanwhile may show without styles. The smoke test opens each public page and its stylesheet right after the deployment.
- **Share cards.** `deploy/social-card.php` draws the 1200×630 Open Graph image with PHP GD (no extra software): `php deploy/social-card.php out.png regular.ttf bold.ttf "Line 1" "Line 2" "Accent" "Subtitle" "Footer" [monogram]`.
