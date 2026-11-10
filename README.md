# Legacy Site Sunset Toolkit — a GNWT.ca case study

A Drupal 11 module that helps retire the legacy `gov.nt.ca` department sites without breaking a single link.

In September 2026 the Government of the Northwest Territories launched [GNWT.ca](https://gnwt.ca/welcome) (French: [gtno.ca](https://gtno.ca/)), one Drupal 11 portal organised by topic that replaces more than ten Drupal 7 department sites. The old sites stay online during the transition and stop being updated by December 2026. Years of links to them live on in bookmarks, PDFs, emails, partner sites and search results.

This toolkit answers one question for every old URL: **where should a resident who follows it land?**

**Live demo: [sunset.erwanbargain.com](https://sunset.erwanbargain.com)** (Drupal 11.4, PHP 8.5, MySQL 8.4, shared hosting).

> Independent demonstration project, not affiliated with the GNWT. It only reads public data (XML sitemaps and the Internet Archive's index) and does not redistribute GNWT content.

## What it does

```
GNWT.ca sitemap ──► sunset:targets ──┐
                                     ├─► sunset:match ──► review screen ──► 301 redirects
Internet Archive ─► sunset:legacy ───┘     (suggest)      (editors decide)   (legacy hosts)
                                                 │
                         sunset:check (sample) ──┘    sunset:report / sunset:export
```

1. **Inventory the new site** — `drush sunset:targets https://gnwt.ca/sitemap.xml` reads the sitemap index (50,090 URLs on 10 Nov 2026: 30,302 on gnwt.ca, 19,788 on gtno.ca) and keeps the English/French pairs declared with `hreflang`.
2. **Inventory a legacy site** — `drush sunset:legacy www.hss.gov.nt.ca --from=2025` lists the URLs residents and search engines actually reached, from the Internet Archive's CDX index. The legacy sites publish no sitemap and ask crawlers for 10 seconds between requests; the archive costs them nothing.
3. **Suggest a destination** — `drush sunset:match` compares slugs (same words in any order first, then weighted similarity where rare words count more), separately for English and French, and nudges documents towards resource records and news towards the newsroom.
4. **Review** — editors work through *Content » Legacy URLs*: filter by host, confidence or method, approve in bulk, correct a target, or mark a page as having no equivalent. Changing a target marks it as manual; re-running the matcher never overwrites a human decision. Teams that prefer a spreadsheet can import it with the `gnwt_sunset_reviewed_csv` migration.
5. **Redirect** — once a legacy domain points at the platform, every request on it is answered before routing: approved mappings with a **301**, everything else with a temporary **302** to a GNWT.ca search built from the old slug. No resident lands on a 404, and nothing becomes permanent without a human decision.
6. **Verify and export** — `drush sunset:check` samples what legacy URLs and their targets answer today; `drush sunset:report` summarises progress per host; `drush sunset:export` writes Apache `RewriteMap` or nginx `map` files for teams that prefer to redirect at the web server.

## First run: Health and Social Services (hss.gov.nt.ca), 10 November 2026

| Step | Result |
|---|---|
| New site inventory | 50,090 URLs (30,302 English, 19,788 French) |
| Legacy inventory | 143,815 archived captures since 2025 → **7,155 unique URLs**, of which 2,116 documents |
| Suggested page | **3,576 URLs (50%)**: 2,254 same slug, 1,088 similar slug, 234 parent page (content merged) |
| High confidence (≥ 0.85) | 2,298 URLs, ready for bulk review |
| No candidate | 3,579 URLs, including 1,081 whose address carries no words (`/fr/node/4474`): they need the page title |
| Live sample (30 URLs, 10 s apart, 5 min 25 s) | 28 legacy URLs still answer 200 (no redirect in place yet), 2 answered 504; all 14 suggested targets in the sample answer 200 |

The health site also holds **177 documents in Indigenous languages** (Inuktitut, Inuinnaqtun, Inuvialuktun, Tłı̨chǫ, Chipewyan, Cree, Gwich'in, North and South Slavey, going by their file names): 124 already have a suggested GNWT.ca page, 84 with high confidence. These are the links most worth protecting: few in number, and hard for their readers to replace.

Spot checks during tuning showed most suggestions pointing to the right page. The errors found (day numbers dropped from slugs, French documents sent to English pages, sub-pages merged into their parent) were fixed and are covered by tests. Precision is not measured yet: that is what the review queue is for.

## Design decisions

| Decision | Why |
|---|---|
| Inventory legacy sites from the Internet Archive | No sitemap on the D7 sites, `Crawl-delay: 10`, and some answered 504 during testing. The archive lists the URLs people really used, at zero load. |
| A polite HTTP client | One user agent, robots.txt honoured (`Crawl-delay`, `Disallow`), a minimum delay per host, and a circuit breaker that stops calling a host after repeated 5xx. |
| Host-aware redirects instead of the Redirect module | Redirect keys on the path only, but ten legacy hosts share paths such as `/en/newsroom`. Here one indexed lookup on `sha1(host + path)` answers each request. |
| 301 only after review, 302 otherwise | A wrong permanent redirect is cached by browsers and search engines; a temporary fallback can be fixed tomorrow. |
| Normalisation before anything else | `www.`, case, trailing slashes, facets in query strings: one legacy page, one record, one redirect. |
| Fallback search uses the parent's words and the document's language | `/en/services/cannabis/youth` searches "cannabis youth"; `guide-fr.pdf` searches gtno.ca. |
| Schema changes through `hook_update_N` | The data model evolved during tuning; the update ran on a database holding 57,000 records, as it would in production. |
| Entities, Views, actions, Migrate | Standard Drupal building blocks: the review screen, bulk actions and CSV import need no custom UI code. |

## Quick start

Requirements: PHP 8.4+ with `gd`, `pdo_sqlite` and `intl`, Composer 2.

```bash
cd drupal
composer install
mkdir -p db
vendor/bin/drush site:install standard --db-url=sqlite://localhost/../db/sunset.sqlite -y
vendor/bin/drush en gnwt_sunset migrate_plus migrate_tools migrate_source_csv -y
vendor/bin/drush sunset:targets https://gnwt.ca/sitemap.xml
vendor/bin/drush sunset:legacy www.hss.gov.nt.ca --from=2025
vendor/bin/drush sunset:match
vendor/bin/drush sunset:report
vendor/bin/drush sunset:resolve https://www.hss.gov.nt.ca/en/services/medical-travel
```

On Windows, `php/php.ini` enables the needed extensions and a CA bundle without touching the system PHP: `set PHPRC=%CD%\php`.

## Design

A standalone theme generated with Drupal's starterkit (`sunset_theme`): system fonts only (Noto Sans first, for Dene diacritics), WCAG 2.1 AA contrast, visible keyboard focus, no motion when reduced motion is requested, no cookies, tracking, external fonts or scripts for visitors. The overview and the "Try a legacy URL" result are Twig templates provided by the module and styled by the theme; `/try?url=…` results can be shared as links.

## Quality

- Unit tests for normalisation, scoring, robots.txt parsing and CDX paging; a kernel test runs the whole pipeline, including a real request on a legacy host name.
- GitHub Actions: Drupal coding standards (`phpcs --standard=Drupal,DrupalPractice`), `composer audit`, PHPUnit on PHP 8.4 and 8.5 (the server runs 8.5).

## Continuous deployment

Every push to `main` that passes the tests is deployed to the live demo, on shared hosting without SSH (see [docs/deploy.md](docs/deploy.md)):

1. **Build.** GitHub Actions installs production dependencies on the server's PHP version and applies the hosting patches.
2. **Ship only what changed.** The server keeps a manifest of file checksums; the pipeline compares, packs the changed files and sends them over HTTPS to a small receiver, with deletions for files that left the codebase.
3. **Signed, not shared.** Each request is signed with an Ed25519 key held in GitHub secrets; the server holds only the public key. No FTP password in CI, and unsigned calls get a 404.
4. **Run what Drush would.** The receiver rebuilds the service container, runs pending `hook_update_N()` and post-updates in maintenance mode, and rebuilds caches.
5. **Check the live site.** A smoke test loads every public page, its stylesheet and share card, checks the redirect and the 404, and confirms the receiver refuses unsigned calls. The same test runs daily.

## Limits and next steps

- Matching uses slugs only. Fetching page titles from the archive would raise recall for legacy URLs such as `/fr/node/4474` (1,081 on the health site alone).
- robots.txt wildcard rules are ignored (treated as not matching).
- nginx maps are case-sensitive: the Drupal subscriber and the Apache export are not.
- Next: Indigenous-language content audit (which essential pages exist in the nine official Indigenous languages, and where residents find them), and a before/after quality grid for GNWT.ca and gtno.ca (accessibility, French parity, performance, security headers).
