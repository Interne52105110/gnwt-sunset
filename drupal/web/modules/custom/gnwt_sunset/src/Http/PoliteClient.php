<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Http;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client that behaves like a well-mannered crawler.
 *
 * - Identifies itself with a clear user agent.
 * - Reads robots.txt once per host and honours Crawl-delay and Disallow
 *   for the "*" group (wildcard patterns are not interpreted).
 * - Never sends two requests to one host closer than the configured delay.
 * - Stops calling a host after several server errors in a row.
 */
class PoliteClient {

  /**
   * Time of the last request per host, in microseconds.
   *
   * @var array<string, float>
   */
  private array $lastRequestAt = [];

  /**
   * Parsed robots.txt rules per host.
   *
   * @var array<string, array{delay: float, disallow: string[]}>
   */
  private array $robots = [];

  /**
   * Server errors in a row per host.
   *
   * @var array<string, int>
   */
  private array $consecutiveErrors = [];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sends a request once the host's delay has passed.
   *
   * Redirects are not followed and HTTP errors do not throw, so callers can
   * record the status they actually received.
   *
   * @param string $method
   *   HTTP method.
   * @param string $url
   *   Absolute URL.
   * @param array $options
   *   Guzzle request options.
   * @param bool $respectRobots
   *   FALSE only for documented APIs (such as the Wayback CDX server) that
   *   are meant to be queried by programs.
   *
   * @throws \Drupal\gnwt_sunset\Http\HostUnavailableException
   * @throws \Drupal\gnwt_sunset\Http\RobotsDisallowedException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function request(string $method, string $url, array $options = [], bool $respectRobots = TRUE): ResponseInterface {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if (($this->consecutiveErrors[$host] ?? 0) >= $this->maxErrors()) {
      throw new HostUnavailableException(sprintf('%s answered with %d server errors in a row; skipped for this run.', $host, $this->maxErrors()));
    }

    $delay = $this->minDelay();
    if ($respectRobots) {
      $rules = $this->robotsFor($parts['scheme'] ?? 'https', $host);
      $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
      foreach ($rules['disallow'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
          throw new RobotsDisallowedException(sprintf('robots.txt of %s disallows %s', $host, $path));
        }
      }
      $delay = max($delay, $rules['delay']);
    }

    $this->waitFor($host, $delay);
    $options += [
      'timeout' => 30,
      'connect_timeout' => 15,
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ];
    $options['headers']['User-Agent'] = $this->userAgent();

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      $this->recordError($host);
      throw $e;
    }
    finally {
      $this->lastRequestAt[$host] = $this->time->getCurrentMicroTime();
    }

    if ($response->getStatusCode() >= 500) {
      $this->recordError($host);
    }
    else {
      $this->consecutiveErrors[$host] = 0;
    }
    return $response;
  }

  /**
   * Parses the "*" group of a robots.txt file.
   *
   * @return array{delay: float, disallow: string[]}
   *   Crawl delay in seconds and disallowed path prefixes.
   */
  public static function parseRobots(string $body): array {
    $delay = 0.0;
    $disallow = [];
    $applies = FALSE;
    $readingAgents = FALSE;
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
      $line = trim((string) preg_replace('/#.*$/', '', $line));
      if ($line === '' || !str_contains($line, ':')) {
        continue;
      }
      [$field, $value] = array_map('trim', explode(':', $line, 2));
      $field = strtolower($field);
      if ($field === 'user-agent') {
        // Consecutive User-agent lines share one group.
        if (!$readingAgents) {
          $applies = FALSE;
        }
        $readingAgents = TRUE;
        $applies = $applies || $value === '*';
        continue;
      }
      $readingAgents = FALSE;
      if (!$applies) {
        continue;
      }
      if ($field === 'crawl-delay' && is_numeric($value)) {
        $delay = (float) $value;
      }
      elseif ($field === 'disallow' && $value !== '' && !str_contains($value, '*')) {
        $disallow[] = $value;
      }
    }
    return ['delay' => $delay, 'disallow' => array_values(array_unique($disallow))];
  }

  /**
   * Sleeps; overridden in tests.
   */
  protected function pause(float $seconds): void {
    usleep((int) round($seconds * 1_000_000));
  }

  /**
   * Returns the robots.txt rules of a host, fetching them once.
   *
   * @return array{delay: float, disallow: string[]}
   *   Parsed rules; no rules when robots.txt is missing or unreadable.
   */
  private function robotsFor(string $scheme, string $host): array {
    if (!isset($this->robots[$host])) {
      $rules = ['delay' => 0.0, 'disallow' => []];
      $this->waitFor($host, $this->minDelay());
      try {
        $response = $this->httpClient->request('GET', $scheme . '://' . $host . '/robots.txt', [
          'timeout' => 20,
          'http_errors' => FALSE,
          'allow_redirects' => TRUE,
          'headers' => ['User-Agent' => $this->userAgent()],
        ]);
        if ($response->getStatusCode() === 200) {
          $rules = self::parseRobots((string) $response->getBody());
        }
      }
      catch (GuzzleException $e) {
        $this->logger->warning('robots.txt of @host unreadable: @message', [
          '@host' => $host,
          '@message' => $e->getMessage(),
        ]);
      }
      finally {
        $this->lastRequestAt[$host] = $this->time->getCurrentMicroTime();
      }
      $this->robots[$host] = $rules;
    }
    return $this->robots[$host];
  }

  /**
   * Waits until a request to the host is allowed again.
   */
  private function waitFor(string $host, float $delay): void {
    if (!isset($this->lastRequestAt[$host])) {
      return;
    }
    $remaining = $this->lastRequestAt[$host] + $delay - $this->time->getCurrentMicroTime();
    if ($remaining > 0) {
      $this->pause($remaining);
    }
  }

  /**
   * Counts a server error for a host.
   */
  private function recordError(string $host): void {
    $this->consecutiveErrors[$host] = ($this->consecutiveErrors[$host] ?? 0) + 1;
  }

  /**
   * Configured user agent.
   */
  private function userAgent(): string {
    return (string) $this->configFactory->get('gnwt_sunset.settings')->get('user_agent');
  }

  /**
   * Configured minimum delay between two requests to one host.
   */
  private function minDelay(): float {
    return max(0.0, (float) $this->configFactory->get('gnwt_sunset.settings')->get('min_delay'));
  }

  /**
   * Configured number of server errors in a row before giving up on a host.
   */
  private function maxErrors(): int {
    return max(1, (int) $this->configFactory->get('gnwt_sunset.settings')->get('max_consecutive_errors'));
  }

}
