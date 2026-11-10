<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Redirect;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\gnwt_sunset\Entity\SunsetUrl;
use Drupal\gnwt_sunset\UrlNormalizer;

/**
 * Decides where a request for a legacy host and path should go.
 *
 * Why not the Redirect module: its redirects are keyed by path only, while
 * ten legacy hosts share paths such as /en/newsroom. Here every lookup is
 * keyed by host and path, and one indexed query answers it.
 */
final class Resolver {

  public function __construct(
    private readonly UrlNormalizer $normalizer,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves a legacy request, or returns NULL for hosts that are not legacy.
   */
  public function resolve(string $host, string $path): ?Resolution {
    $config = $this->configFactory->get('gnwt_sunset.settings');
    $host = $this->normalizer->canonicalHost($host);
    if (!in_array($host, $config->get('legacy_hosts') ?? [], TRUE)) {
      return NULL;
    }

    $path = $this->normalizer->normalizePath($path);
    [$lang, $segments] = $this->normalizer->splitLanguage($path);
    $lang = $lang === 'fr' ? 'fr' : 'en';
    if ($segments === []) {
      return new Resolution((string) $config->get('home.' . $lang), 301, 'home');
    }

    $row = $this->database->select('sunset_url', 's')
      ->fields('s', ['target_url', 'review', 'confidence'])
      ->condition('url_hash', $this->normalizer->hash($host, $path))
      ->execute()
      ->fetchAssoc();

    if ($row && $row['review'] === SunsetUrl::REVIEW_APPROVED && !empty($row['target_url'])) {
      return new Resolution($row['target_url'], 301, 'approved');
    }
    if ($row
      && $row['review'] === SunsetUrl::REVIEW_PENDING
      && !empty($row['target_url'])
      && $config->get('serve_high_confidence')
      && (float) $row['confidence'] >= (float) $config->get('high_threshold')) {
      return new Resolution($row['target_url'], 302, 'suggested');
    }

    // Temporary fallback: a search on the new site with the words of the
    // parent and of the page ("cannabis youth", not just "youth"), in the
    // language of the page or of the document ("guide-fr.pdf").
    $normalized = $this->normalizer->normalize('https://' . $host . $path);
    if ($normalized?->lang === 'fr') {
      $lang = 'fr';
    }
    $words = array_unique(array_merge($normalized->parentTokens ?? [], $normalized->tokens ?? []));
    // "/fr/node/4474" has no words to search for: the home page is better.
    $fallback = $words === []
      ? (string) $config->get('home.' . $lang)
      : strtr((string) $config->get('fallback.' . $lang), ['{keywords}' => rawurlencode(implode(' ', $words))]);
    $reason = match (TRUE) {
      !$row => 'unknown',
      $row['review'] === SunsetUrl::REVIEW_GONE => 'gone',
      default => 'unreviewed',
    };
    return new Resolution($fallback, 302, $reason);
  }

}
