# Five-minute walkthrough (interview script)

Plain English, short sentences. Numbers from the first run on the health site, 10 November 2026.

## 1. The problem (45 s)

"In September, GNWT.ca replaced more than ten department websites. The old gov.nt.ca sites stop being updated in December. But their links live on: in bookmarks, in PDFs, in emails, on partner websites and in Google. When the old sites go away, every one of those links must still lead somewhere useful. So I built a small Drupal 11 module to plan and serve those redirects."

## 2. Inventory without loading the old servers (60 s)

"First I need two lists: the pages of the new site, and the URLs of an old site.

The new site is easy: GNWT.ca has an XML sitemap. That gives 50,090 pages, in English and in French.

The old sites have no sitemap, and their robots.txt asks for ten seconds between requests. Crawling them would take days and add load to servers that are already slow. So I use the Internet Archive's index instead. It lists the URLs that people and search engines really used. For Health and Social Services alone, that is 7,155 pages and documents since 2025. It costs the GNWT servers nothing."

## 3. Suggestions, not decisions (60 s)

"Then the module suggests a new page for each old URL. It compares the words in the old and new addresses. Rare words count more than common ones: 'tuberculosis' says more than 'program'. English is matched with gnwt.ca, French with gtno.ca. A PDF is pushed towards the resource page, a news item towards the newsroom.

Half of them get a suggestion, and a third with high confidence. When a small page was merged into a bigger one on the new site, the module suggests the parent page, with lower confidence. But a suggestion is not a decision."

## 4. Editors decide (45 s)

"Editors open a review screen in the Drupal admin: Content, Legacy URLs. They filter by site or confidence, approve in bulk, fix a target, or say 'no equivalent'. If they prefer a spreadsheet, they can import it with the Migrate API. A human decision is never overwritten when the matcher runs again."

## 5. Redirects that never break (45 s)

"When an old domain points at the new platform, the module answers before Drupal's routing. Approved URLs get a permanent 301. Everything else gets a temporary 302 to a GNWT.ca search with the old page's words. So nobody lands on a 404, and nothing becomes permanent without a review.

I did not use the Redirect module, because it only looks at the path, and ten old sites share paths like /en/newsroom. Here the key is the host plus the path."

## 6. Quality and limits (45 s)

"It has unit tests and a kernel test that runs the whole chain, including a real request on an old host name. GitHub Actions checks Drupal coding standards, runs composer audit and the tests on PHP 8.3 and 8.4.

Limits: it matches on addresses, not on page titles yet, and the nginx export is case-sensitive. My next step would be an audit of Indigenous-language content: which essential pages exist in the nine official Indigenous languages, and whether residents can find them."

## Likely questions

- **Why not crawl the old sites?** Ten seconds per page, tens of thousands of URLs, and 504 errors during testing. The archive gives the list for free; I only check a small sample live.
- **What if the archive misses a URL?** The fallback still sends the visitor to a search on GNWT.ca, and the 302 responses show which URLs people still use, so they can be reviewed later.
- **Why SQLite?** Only for the demo. The module uses the standard database API and entity storage, so it runs on MySQL or PostgreSQL unchanged.
- **How would you deploy it?** Through Git and Composer like any custom module, config exported with `drush cex`, and `drush deploy` on the server.
