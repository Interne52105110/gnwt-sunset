<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gnwt_sunset\Http\HostUnavailableException;
use Drupal\gnwt_sunset\Http\PoliteClient;
use Drupal\gnwt_sunset\Http\RobotsDisallowedException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Records what a sample of legacy URLs and their targets answer today.
 */
final class LiveChecker {

  public function __construct(
    private readonly PoliteClient $http,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Checks a random sample of legacy URLs.
   *
   * @param string|null $host
   *   Limit to one canonical legacy host.
   * @param int $limit
   *   Sample size. Legacy sites ask for 10 s between requests, so 50 URLs
   *   take about eight minutes.
   * @param bool $onlyUnchecked
   *   Skip URLs checked before.
   * @param callable|null $progress
   *   Called after each URL with the entity and the running statistics.
   *
   * @return array<string, int>
   *   Counts by legacy status class (2xx, 3xx, 4xx, 5xx, error, skipped)
   *   and broken targets.
   */
  public function run(?string $host, int $limit, bool $onlyUnchecked = TRUE, ?callable $progress = NULL): array {
    $query = $this->database->select('sunset_url', 's')->fields('s', ['id']);
    if ($host !== NULL) {
      $query->condition('host', $host);
    }
    if ($onlyUnchecked) {
      $query->isNull('checked');
    }
    $ids = $query->orderRandom()->range(0, $limit)->execute()->fetchCol();

    $stats = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'error' => 0, 'skipped' => 0, 'broken_targets' => 0];
    $storage = $this->entityTypeManager->getStorage('sunset_url');
    $unavailable = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $entityHost = (string) $entity->get('host')->value;
      if (isset($unavailable[$entityHost])) {
        $stats['skipped']++;
        continue;
      }
      try {
        $response = $this->http->request('HEAD', (string) $entity->get('url')->value);
        $status = $response->getStatusCode();
        $entity->set('live_status', $status);
        $entity->set('live_location', $response->getHeaderLine('Location') ?: NULL);
        $stats[intdiv($status, 100) . 'xx'] = ($stats[intdiv($status, 100) . 'xx'] ?? 0) + 1;
      }
      catch (HostUnavailableException) {
        $unavailable[$entityHost] = TRUE;
        $stats['skipped']++;
        continue;
      }
      catch (RobotsDisallowedException) {
        $stats['skipped']++;
        continue;
      }
      catch (GuzzleException) {
        $entity->set('live_status', 0);
        $stats['error']++;
      }

      $target = (string) $entity->get('target_url')->value;
      if ($target !== '') {
        try {
          $targetStatus = $this->http->request('HEAD', $target)->getStatusCode();
        }
        catch (HostUnavailableException | RobotsDisallowedException | GuzzleException) {
          $targetStatus = 0;
        }
        $entity->set('target_status', $targetStatus);
        if ($targetStatus !== 200) {
          $stats['broken_targets']++;
        }
      }

      $entity->set('checked', $this->time->getRequestTime());
      $entity->save();
      if ($progress !== NULL) {
        $progress($entity, $stats);
      }
    }
    return $stats;
  }

}
