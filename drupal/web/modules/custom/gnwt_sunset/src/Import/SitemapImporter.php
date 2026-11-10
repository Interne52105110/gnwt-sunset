<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gnwt_sunset\Http\PoliteClient;
use Drupal\gnwt_sunset\UrlNormalizer;

/**
 * Loads the pages of the new site from its XML sitemap.
 */
final class SitemapImporter {

  private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
  private const NS_XHTML = 'http://www.w3.org/1999/xhtml';

  public function __construct(
    private readonly PoliteClient $http,
    private readonly UrlNormalizer $normalizer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Imports a sitemap (or sitemap index) into sunset_target records.
   *
   * @param string $source
   *   URL of the sitemap, or a local directory of saved sitemap files, which
   *   makes runs reproducible and spares the live site.
   * @param callable|null $progress
   *   Called with the running statistics every 1000 URLs.
   *
   * @return array<string, int>
   *   Counts: seen, created, updated, unchanged, skipped.
   */
  public function import(string $source, ?callable $progress = NULL): array {
    $languages = [];
    foreach ($this->configFactory->get('gnwt_sunset.settings')->get('target_hosts') ?? [] as $target) {
      $languages[$this->normalizer->canonicalHost($target['host'])] = $target['langcode'];
    }

    $existing = $this->database->select('sunset_target', 't')
      ->fields('t', ['url_hash', 'id', 'lastmod'])
      ->execute()
      ->fetchAllAssoc('url_hash', \PDO::FETCH_ASSOC);
    $storage = $this->entityTypeManager->getStorage('sunset_target');
    $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

    $documents = is_dir($source) ? $this->readDirectory($source) : $this->fetch($source);
    foreach ($documents as $xml) {
      foreach ($this->parseUrlset($xml) as $item) {
        $stats['seen']++;
        $normalized = $this->normalizer->normalize($item['loc']);
        if ($normalized === NULL || !isset($languages[$normalized->host])) {
          $stats['skipped']++;
          continue;
        }
        $lastmod = $item['lastmod'] !== '' ? (strtotime($item['lastmod']) ?: NULL) : NULL;
        $values = [
          'url' => $item['loc'],
          'url_hash' => $normalized->hash,
          'host' => $normalized->host,
          'path' => $normalized->path,
          'lang' => $languages[$normalized->host],
          'section' => $normalized->section,
          'slug' => $normalized->slug,
          'tokens' => implode(' ', $normalized->tokens),
          'alternate_url' => $item['alternate'],
          'lastmod' => $lastmod,
        ];

        if (isset($existing[$normalized->hash])) {
          if ((int) $existing[$normalized->hash]['lastmod'] === (int) $lastmod) {
            $stats['unchanged']++;
            continue;
          }
          $entity = $storage->load($existing[$normalized->hash]['id']);
          foreach ($values as $field => $value) {
            $entity->set($field, $value);
          }
          $entity->save();
          $stats['updated']++;
        }
        else {
          $entity = $storage->create($values);
          $entity->save();
          $existing[$normalized->hash] = ['id' => $entity->id(), 'lastmod' => $lastmod];
          $stats['created']++;
        }

        if ($progress !== NULL && $stats['seen'] % 1000 === 0) {
          $progress($stats);
        }
      }
    }
    return $stats;
  }

  /**
   * Parses the <url> entries of a urlset document.
   *
   * @return \Generator<array{loc: string, lastmod: string, alternate: string}>
   *   One entry per URL; "alternate" is the other-language version, if any.
   */
  public function parseUrlset(string $xml): \Generator {
    $document = @simplexml_load_string($xml);
    if ($document === FALSE || $document->getName() !== 'urlset') {
      return;
    }
    foreach ($document->children(self::NS_SITEMAP)->url as $url) {
      $fields = $url->children(self::NS_SITEMAP);
      $loc = trim((string) $fields->loc);
      if ($loc === '') {
        continue;
      }
      $alternate = '';
      foreach ($url->children(self::NS_XHTML)->link as $link) {
        $href = trim((string) $link->attributes()->href);
        if ($href !== '' && $href !== $loc) {
          $alternate = $href;
          break;
        }
      }
      yield ['loc' => $loc, 'lastmod' => trim((string) $fields->lastmod), 'alternate' => $alternate];
    }
  }

  /**
   * Fetches a sitemap and, for an index, every sitemap it lists.
   *
   * @return \Generator<string>
   *   XML documents.
   */
  private function fetch(string $url): \Generator {
    $body = $this->get($url);
    $document = @simplexml_load_string($body);
    if ($document !== FALSE && $document->getName() === 'sitemapindex') {
      foreach ($document->children(self::NS_SITEMAP)->sitemap as $sitemap) {
        yield $this->get(trim((string) $sitemap->children(self::NS_SITEMAP)->loc));
      }
      return;
    }
    yield $body;
  }

  /**
   * Reads saved sitemap files from a directory, in name order.
   *
   * @return \Generator<string>
   *   XML documents.
   */
  private function readDirectory(string $directory): \Generator {
    $files = glob(rtrim($directory, '/\\') . '/*.xml') ?: [];
    natsort($files);
    foreach ($files as $file) {
      yield (string) file_get_contents($file);
    }
  }

  /**
   * Downloads one document.
   */
  private function get(string $url): string {
    $response = $this->http->request('GET', $url, ['timeout' => 60]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(sprintf('%s answered %d.', $url, $response->getStatusCode()));
    }
    return (string) $response->getBody();
  }

}
