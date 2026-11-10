<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Import;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gnwt_sunset\Entity\SunsetUrl;
use Drupal\gnwt_sunset\Http\PoliteClient;
use Drupal\gnwt_sunset\UrlNormalizer;

/**
 * Lists the URLs of a legacy site from the Internet Archive's CDX index.
 *
 * The old Drupal 7 sites publish no sitemap and ask crawlers for 10 seconds
 * between requests. The archive already knows which URLs residents and
 * search engines reached, so the inventory costs the legacy servers nothing.
 */
final class WaybackImporter {

  private const CDX_ENDPOINT = 'https://web.archive.org/cdx/search/cdx';
  private const PAGE_SIZE = 25000;
  private const ACCEPTED_MIME = [
    'text/html',
    'application/pdf',
    'application/msword',
    'application/vnd.ms-excel',
    'application/vnd.ms-powerpoint',
    'text/csv',
  ];

  public function __construct(
    private readonly PoliteClient $http,
    private readonly UrlNormalizer $normalizer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Imports the archived URLs of one legacy host.
   *
   * @param string $host
   *   Host as archived, for example "www.hss.gov.nt.ca".
   * @param string[] $prefixes
   *   Path prefixes to list, for example ["/en/", "/fr/", "/sites/"].
   * @param string $from
   *   Oldest capture to consider, as YYYY or YYYYMMDD.
   * @param string|null $cdxFile
   *   Saved CDX JSON output to use instead of querying the archive.
   * @param callable|null $progress
   *   Called with the running statistics.
   *
   * @return array<string, int>
   *   Counts: rows, excluded, unique, created, updated, unchanged.
   */
  public function import(string $host, array $prefixes, string $from, ?string $cdxFile = NULL, ?callable $progress = NULL): array {
    $canonicalHost = $this->normalizer->canonicalHost($host);
    $stats = ['rows' => 0, 'excluded' => 0, 'unique' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];

    // Collapse captures into one record per normalized URL.
    $found = [];
    $rows = $cdxFile !== NULL ? $this->readFile($cdxFile) : $this->query($host, $prefixes, $from);
    foreach ($rows as $row) {
      $stats['rows']++;
      $mime = strtolower($row['mimetype'] ?? '');
      $isOffice = str_starts_with($mime, 'application/vnd.openxmlformats-officedocument');
      if (!$isOffice && !in_array($mime, self::ACCEPTED_MIME, TRUE)) {
        $stats['excluded']++;
        continue;
      }
      $normalized = $this->normalizer->normalize($row['original'] ?? '');
      if ($normalized === NULL || $normalized->host !== $canonicalHost) {
        $stats['excluded']++;
        continue;
      }
      $time = $this->timestamp($row['timestamp'] ?? '');
      if (!isset($found[$normalized->hash])) {
        $found[$normalized->hash] = ['url' => $normalized, 'first' => $time, 'last' => $time];
        continue;
      }
      $found[$normalized->hash]['first'] = min($found[$normalized->hash]['first'], $time);
      $found[$normalized->hash]['last'] = max($found[$normalized->hash]['last'], $time);
    }
    $stats['unique'] = count($found);

    $existing = $this->database->select('sunset_url', 's')
      ->fields('s', ['url_hash', 'id', 'first_seen', 'last_seen'])
      ->condition('host', $canonicalHost)
      ->execute()
      ->fetchAllAssoc('url_hash', \PDO::FETCH_ASSOC);
    $storage = $this->entityTypeManager->getStorage('sunset_url');

    foreach ($found as $hash => $item) {
      /** @var \Drupal\gnwt_sunset\NormalizedUrl $url */
      $url = $item['url'];
      if (isset($existing[$hash])) {
        $first = min((int) $existing[$hash]['first_seen'] ?: PHP_INT_MAX, $item['first']);
        $last = max((int) $existing[$hash]['last_seen'], $item['last']);
        if ($first === (int) $existing[$hash]['first_seen'] && $last === (int) $existing[$hash]['last_seen']) {
          $stats['unchanged']++;
          continue;
        }
        $entity = $storage->load($existing[$hash]['id']);
        $entity->set('first_seen', $first);
        $entity->set('last_seen', $last);
        $entity->save();
        $stats['updated']++;
      }
      else {
        $storage->create([
          'url' => $url->url,
          'source' => 'wayback',
          'first_seen' => $item['first'],
          'last_seen' => $item['last'],
          'review' => SunsetUrl::REVIEW_PENDING,
        ])->save();
        $stats['created']++;
      }
      if ($progress !== NULL && ($stats['created'] + $stats['updated']) % 500 === 0) {
        $progress($stats);
      }
    }
    return $stats;
  }

  /**
   * Queries the CDX server, following resume keys.
   *
   * @return \Generator<array<string, string>>
   *   Rows keyed by CDX field name.
   */
  private function query(string $host, array $prefixes, string $from): \Generator {
    foreach ($prefixes as $prefix) {
      $resumeKey = NULL;
      do {
        $parameters = [
          'url' => $host . $prefix . '*',
          'output' => 'json',
          'fl' => 'original,mimetype,timestamp',
          'collapse' => 'urlkey',
          'filter' => 'statuscode:200',
          'from' => $from,
          'limit' => self::PAGE_SIZE,
          'showResumeKey' => 'true',
        ];
        if ($resumeKey !== NULL) {
          $parameters['resumeKey'] = $resumeKey;
        }
        $response = $this->http->request('GET', self::CDX_ENDPOINT . '?' . http_build_query($parameters), ['timeout' => 300], FALSE);
        if ($response->getStatusCode() !== 200) {
          throw new \RuntimeException(sprintf('The CDX server answered %d for %s%s.', $response->getStatusCode(), $host, $prefix));
        }
        $data = json_decode((string) $response->getBody(), TRUE) ?: [];
        [$rows, $resumeKey] = self::splitResumeKey($data);
        yield from self::rowsWithHeader($rows);
      } while ($resumeKey !== NULL);
    }
  }

  /**
   * Reads saved CDX JSON output.
   *
   * @return \Generator<array<string, string>>
   *   Rows keyed by CDX field name.
   */
  private function readFile(string $file): \Generator {
    if (!is_readable($file)) {
      throw new \RuntimeException(sprintf('Cannot read %s.', $file));
    }
    $data = json_decode((string) file_get_contents($file), TRUE) ?: [];
    [$rows] = self::splitResumeKey($data);
    yield from self::rowsWithHeader($rows);
  }

  /**
   * Separates the trailing resume key from CDX JSON output.
   *
   * With showResumeKey, the server ends the list with an empty row followed
   * by a row holding the key.
   *
   * @return array{0: array, 1: string|null}
   *   The rows (header first) and the resume key, if any.
   */
  public static function splitResumeKey(array $data): array {
    $count = count($data);
    if ($count >= 2 && $data[$count - 2] === [] && isset($data[$count - 1][0])) {
      return [array_slice($data, 0, $count - 2), (string) $data[$count - 1][0]];
    }
    return [$data, NULL];
  }

  /**
   * Turns CDX rows into associative arrays using the header row.
   *
   * @return \Generator<array<string, string>>
   *   Rows keyed by field name.
   */
  private static function rowsWithHeader(array $rows): \Generator {
    $header = array_shift($rows);
    if (!is_array($header)) {
      return;
    }
    foreach ($rows as $row) {
      if (is_array($row) && count($row) === count($header)) {
        yield array_combine($header, $row);
      }
    }
  }

  /**
   * Converts a 14-digit CDX timestamp to a Unix timestamp.
   */
  private function timestamp(string $value): int {
    $date = \DateTimeImmutable::createFromFormat('YmdHis', str_pad(substr($value, 0, 14), 14, '0'), new \DateTimeZone('UTC'));
    return $date !== FALSE ? $date->getTimestamp() : 0;
  }

}
