<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gnwt_sunset\Entity\SunsetUrl;
use Drupal\gnwt_sunset\Import\SitemapImporter;
use Drupal\gnwt_sunset\Import\WaybackImporter;
use Drupal\gnwt_sunset\LiveChecker;
use Drupal\gnwt_sunset\Matching\Matcher;
use Drupal\gnwt_sunset\Package\DataPackage;
use Drupal\gnwt_sunset\Redirect\Resolver;
use Drupal\gnwt_sunset\UrlNormalizer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Drush commands of the sunset toolkit.
 */
final class SunsetCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly SitemapImporter $sitemapImporter,
    private readonly WaybackImporter $waybackImporter,
    private readonly Matcher $matcher,
    private readonly LiveChecker $checker,
    private readonly Resolver $resolver,
    private readonly UrlNormalizer $normalizer,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DataPackage $package,
  ) {
    parent::__construct();
  }

  /**
   * Writes the inventory to a package for a server without a shell.
   */
  #[CLI\Command(name: 'sunset:package')]
  #[CLI\Argument(name: 'file', description: 'Package file to write (.jsonl.gz).')]
  #[CLI\Usage(name: 'drush sunset:package ../data/sunset-package.jsonl.gz', description: 'Then upload it next to vendor/ and use the Import data tab.')]
  public function package(string $file): void {
    $counts = $this->package->export($this->localPath($file));
    $this->io()->success(sprintf('%d GNWT.ca pages and %d legacy URLs written to %s.', $counts['sunset_target'], $counts['sunset_url'], $file));
  }

  /**
   * Imports the pages of the new site from its XML sitemap.
   */
  #[CLI\Command(name: 'sunset:targets')]
  #[CLI\Argument(name: 'source', description: 'Sitemap URL, or a directory of saved sitemap files.')]
  #[CLI\Usage(name: 'drush sunset:targets https://gnwt.ca/sitemap.xml', description: 'Read the live sitemap index and every page it lists.')]
  #[CLI\Usage(name: 'drush sunset:targets ../data/raw/gnwt_sitemap', description: 'Replay saved sitemap files.')]
  public function targets(string $source): void {
    if (!preg_match('#^https?://#', $source)) {
      $source = $this->localPath($source);
      if (!is_dir($source)) {
        throw new \InvalidArgumentException(sprintf('%s is neither an http(s) URL nor a directory.', $source));
      }
    }
    $stats = $this->sitemapImporter->import($source, fn (array $s) => $this->logger()->notice(sprintf('%d URLs read…', $s['seen'])));
    $this->io()->success(vsprintf('%d URLs read: %d created, %d updated, %d unchanged, %d skipped (unknown host or excluded path).', [
      $stats['seen'], $stats['created'], $stats['updated'], $stats['unchanged'], $stats['skipped'],
    ]));
  }

  /**
   * Lists the URLs of a legacy host from the Internet Archive.
   */
  #[CLI\Command(name: 'sunset:legacy')]
  #[CLI\Argument(name: 'host', description: 'Legacy host as archived, for example www.hss.gov.nt.ca.')]
  #[CLI\Option(name: 'prefixes', description: 'Comma-separated path prefixes to list.')]
  #[CLI\Option(name: 'from', description: 'Oldest capture to consider (YYYY or YYYYMMDD).')]
  #[CLI\Option(name: 'cdx-file', description: 'Saved CDX JSON output to use instead of querying the archive.')]
  #[CLI\Usage(name: 'drush sunset:legacy www.hss.gov.nt.ca --from=2025', description: 'Pages and documents archived since 2025.')]
  public function legacy(
    string $host,
    array $options = [
      'prefixes' => '/en/,/fr/,/sites/',
      'from' => '2025',
      'cdx-file' => NULL,
    ],
  ): void {
    $prefixes = array_values(array_filter(array_map('trim', explode(',', (string) $options['prefixes']))));
    $cdxFile = $options['cdx-file'] ? $this->localPath((string) $options['cdx-file']) : NULL;
    $stats = $this->waybackImporter->import($host, $prefixes, (string) $options['from'], $cdxFile);
    $this->io()->success(vsprintf('%d archive rows, %d excluded, %d unique URLs: %d created, %d updated, %d unchanged.', [
      $stats['rows'], $stats['excluded'], $stats['unique'], $stats['created'], $stats['updated'], $stats['unchanged'],
    ]));
  }

  /**
   * Suggests a GNWT.ca page for legacy URLs waiting for review.
   */
  #[CLI\Command(name: 'sunset:match')]
  #[CLI\Option(name: 'host', description: 'Limit to one legacy host.')]
  #[CLI\Option(name: 'rematch', description: 'Recompute earlier suggestions too (reviewed and manual mappings are kept).')]
  public function match(array $options = ['host' => NULL, 'rematch' => FALSE]): void {
    $stats = $this->matcher->run($options['host'] ?: NULL, (bool) $options['rematch'], fn (array $s) => $this->logger()->notice(sprintf('%d matched…', $s['processed'])));
    $this->io()->success(vsprintf('%d URLs processed: %d same slug, %d similar slug, %d parent page, %d without candidate; %d high confidence.', [
      $stats['processed'], $stats['exact'], $stats['fuzzy'], $stats['parent'], $stats['none'], $stats['high'],
    ]));
  }

  /**
   * Recomputes slug words after the tokenizer changed.
   *
   * Unreviewed suggestions are cleared so that sunset:match recomputes
   * them; approved, rejected and manual mappings are kept.
   */
  #[CLI\Command(name: 'sunset:retokenize')]
  #[CLI\Usage(name: 'drush sunset:retokenize && drush sunset:match', description: 'Apply a tuned tokenizer to existing records.')]
  public function retokenize(): void {
    $counts = ['sunset_target' => 0, 'sunset_url' => 0];
    foreach (array_keys($counts) as $table) {
      $isLegacy = $table === 'sunset_url';
      $fields = $isLegacy ? ['id', 'url', 'review', 'method'] : ['id', 'url'];
      $rows = $this->database->select($table, 't')->fields('t', $fields)->execute()->fetchAll();
      foreach (array_chunk($rows, 1000) as $chunk) {
        $transaction = $this->database->startTransaction();
        foreach ($chunk as $row) {
          $normalized = $this->normalizer->normalize($row->url);
          if ($normalized === NULL) {
            continue;
          }
          $values = ['tokens' => implode(' ', $normalized->tokens), 'section' => $normalized->section];
          if ($isLegacy) {
            $values['parent_tokens'] = implode(' ', $normalized->parentTokens);
            $values['lang'] = $normalized->lang;
            if ($row->review === SunsetUrl::REVIEW_PENDING && $row->method !== 'manual') {
              $values += ['method' => NULL, 'target_url' => NULL, 'confidence' => NULL];
            }
          }
          $this->database->update($table)->fields($values)->condition('id', $row->id)->execute();
          $counts[$table]++;
        }
        unset($transaction);
      }
    }
    $this->entityTypeManager->getStorage('sunset_url')->resetCache();
    $this->entityTypeManager->getStorage('sunset_target')->resetCache();
    Cache::invalidateTags(['sunset_url_list', 'sunset_target_list']);
    $this->io()->success(sprintf('Slug words recomputed for %d GNWT.ca pages and %d legacy URLs. Run drush sunset:match next.', $counts['sunset_target'], $counts['sunset_url']));
  }

  /**
   * Checks what a random sample of legacy URLs and their targets answer.
   */
  #[CLI\Command(name: 'sunset:check')]
  #[CLI\Option(name: 'host', description: 'Limit to one legacy host (without www.).')]
  #[CLI\Option(name: 'limit', description: 'Sample size.')]
  #[CLI\Option(name: 'recheck', description: 'Include URLs checked before.')]
  #[CLI\Usage(name: 'drush sunset:check --host=hss.gov.nt.ca --limit=30', description: 'About five minutes at the 10 s Crawl-delay of the legacy sites.')]
  public function check(array $options = ['host' => NULL, 'limit' => 30, 'recheck' => FALSE]): void {
    $stats = $this->checker->run($options['host'] ?: NULL, (int) $options['limit'], !$options['recheck'], function (SunsetUrl $url, array $stats): void {
      $this->logger()->notice(sprintf('%s → %s', $url->get('url')->value, $url->get('live_status')->value));
    });
    $summary = [];
    foreach ($stats as $key => $count) {
      $summary[] = $key . ': ' . $count;
    }
    $this->io()->success(implode(', ', $summary));
  }

  /**
   * Summarises the inventory per legacy host.
   */
  #[CLI\Command(name: 'sunset:report')]
  #[CLI\FieldLabels(labels: [
    'host' => 'Legacy host',
    'total' => 'URLs',
    'files' => 'Documents',
    'exact' => 'Same slug',
    'fuzzy' => 'Similar slug',
    'parent' => 'Parent page',
    'no_match' => 'No candidate',
    'high' => 'High confidence',
    'approved' => 'Approved',
    'gone' => 'No equivalent',
    'checked' => 'Checked',
    'live_404' => 'Already 404',
  ])]
  public function report(array $options = ['format' => 'table']): RowsOfFields {
    $high = (float) $this->configFactory->get('gnwt_sunset.settings')->get('high_threshold');
    $result = $this->database->query("SELECT host, COUNT(*) AS total,
        SUM(CASE WHEN kind = 'file' THEN 1 ELSE 0 END) AS files,
        SUM(CASE WHEN method = 'slug_exact' THEN 1 ELSE 0 END) AS exact,
        SUM(CASE WHEN method = 'slug_fuzzy' THEN 1 ELSE 0 END) AS fuzzy,
        SUM(CASE WHEN method = 'parent' THEN 1 ELSE 0 END) AS parent,
        SUM(CASE WHEN method = 'none' THEN 1 ELSE 0 END) AS no_match,
        SUM(CASE WHEN target_url IS NOT NULL AND confidence >= :high THEN 1 ELSE 0 END) AS high,
        SUM(CASE WHEN review = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN review = 'gone' THEN 1 ELSE 0 END) AS gone,
        SUM(CASE WHEN checked IS NOT NULL THEN 1 ELSE 0 END) AS checked,
        SUM(CASE WHEN live_status = 404 THEN 1 ELSE 0 END) AS live_404
      FROM {sunset_url} GROUP BY host ORDER BY total DESC", [':high' => $high]);
    return new RowsOfFields($result->fetchAll(\PDO::FETCH_ASSOC));
  }

  /**
   * Exports the approved mappings of a host for the web server.
   */
  #[CLI\Command(name: 'sunset:export')]
  #[CLI\Argument(name: 'host', description: 'Legacy host (without www.).')]
  #[CLI\Option(name: 'format', description: 'apache (RewriteMap txt), nginx (map block) or csv.')]
  #[CLI\Option(name: 'include-suggested', description: 'Also export unreviewed high-confidence suggestions.')]
  #[CLI\Usage(name: 'drush sunset:export hss.gov.nt.ca --format=apache > hss.map', description: 'Use with RewriteMap and int:tolower.')]
  public function export(string $host, array $options = ['format' => 'apache', 'include-suggested' => FALSE]): string {
    $query = $this->database->select('sunset_url', 's')
      ->fields('s', ['path', 'target_url', 'review'])
      ->condition('host', $this->normalizer->canonicalHost($host))
      ->isNotNull('target_url')
      ->orderBy('path');
    $states = $query->orConditionGroup()->condition('review', SunsetUrl::REVIEW_APPROVED);
    if ($options['include-suggested']) {
      $high = (float) $this->configFactory->get('gnwt_sunset.settings')->get('high_threshold');
      $states->condition($query->andConditionGroup()
        ->condition('review', SunsetUrl::REVIEW_PENDING)
        ->condition('confidence', $high, '>='));
    }
    $query->condition($states);

    $lines = [];
    $skipped = 0;
    foreach ($query->execute() as $row) {
      // Map files are whitespace-separated: keys with spaces cannot be used.
      if (preg_match('/\s/', $row->path)) {
        $skipped++;
        continue;
      }
      $lines[] = match ($options['format']) {
        'nginx' => sprintf('    "%s" "%s";', addcslashes($row->path, '"'), addcslashes($row->target_url, '"')),
        'csv' => sprintf('%s,%s,%s', $row->path, $row->target_url, $row->review),
        default => $row->path . ' ' . $row->target_url,
      };
    }
    if ($skipped > 0) {
      $this->logger()->warning(sprintf('%d paths with spaces were left out; serve them through Drupal.', $skipped));
    }

    return match ($options['format']) {
      'nginx' => "# Paths are lowercase: nginx matches \$uri case-sensitively.\nmap \$uri \$gnwt_sunset_target {\n    default \"\";\n" . implode("\n", $lines) . "\n}\n",
      'csv' => "path,target_url,review\n" . implode("\n", $lines) . "\n",
      default => "# RewriteMap sunset txt:/path/to/this/file\n# RewriteMap lc int:tolower\n# RewriteCond \${sunset:\${lc:%{REQUEST_URI}}} ^(.+)\$\n# RewriteRule ^ %1 [R=301,L]\n" . implode("\n", $lines) . "\n",
    };
  }

  /**
   * Resolves a path against the directory Drush was started from.
   *
   * Drush moves to the Drupal root while bootstrapping, so relative paths
   * typed by the user would otherwise point into web/.
   */
  private function localPath(string $path): string {
    return Path::makeAbsolute($path, $this->getConfig()->cwd());
  }

  /**
   * Shows where a legacy URL would be redirected.
   */
  #[CLI\Command(name: 'sunset:resolve')]
  #[CLI\Argument(name: 'url', description: 'A legacy URL.')]
  #[CLI\Usage(name: 'drush sunset:resolve https://www.hss.gov.nt.ca/en/services/medical-travel', description: 'Prints the status, the reason and the destination.')]
  public function resolve(string $url): void {
    $parts = parse_url($url);
    $resolution = $this->resolver->resolve($parts['host'] ?? '', $parts['path'] ?? '/');
    if ($resolution === NULL) {
      $this->io()->warning('Not a legacy host: no redirect.');
      return;
    }
    $this->io()->writeln(sprintf('%d (%s) → %s', $resolution->status, $resolution->reason, $resolution->url));
  }

}
