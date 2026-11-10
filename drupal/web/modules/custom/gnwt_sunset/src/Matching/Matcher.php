<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Matching;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\gnwt_sunset\Entity\SunsetUrl;
use Drupal\gnwt_sunset\UrlNormalizer;

/**
 * Suggests a GNWT.ca page for each legacy URL that waits for review.
 *
 * Suggestions are only suggestions: nothing redirects until an editor
 * approves them (unless an administrator opts in to serving unreviewed
 * high-confidence matches as temporary 302 redirects).
 */
final class Matcher {

  /**
   * Target pages by id: url, lang, section, tokens.
   *
   * @var array<int, array{url: string, lang: string, section: string, tokens: string[]}>
   */
  private array $targets = [];

  /**
   * Target ids per language and exact slug key.
   *
   * @var array<string, array<string, int[]>>
   */
  private array $exact = [];

  /**
   * Target ids per language and token.
   *
   * @var array<string, array<string, int[]>>
   */
  private array $postings = [];

  /**
   * Inverse document frequency per language and token.
   *
   * @var array<string, array<string, float>>
   */
  private array $idf = [];

  /**
   * Number of target pages per language.
   *
   * @var array<string, int>
   */
  private array $documents = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UrlNormalizer $normalizer,
  ) {}

  /**
   * Matches legacy URLs that wait for review.
   *
   * @param string|null $host
   *   Limit to one legacy host.
   * @param bool $rematch
   *   Also recompute URLs matched before. Approved, rejected and manual
   *   mappings are never touched.
   * @param callable|null $progress
   *   Called with the running statistics every 500 URLs.
   *
   * @return array<string, int>
   *   Counts: processed, exact, fuzzy, none, high.
   */
  public function run(?string $host = NULL, bool $rematch = FALSE, ?callable $progress = NULL): array {
    $config = $this->configFactory->get('gnwt_sunset.settings');
    $suggestThreshold = (float) $config->get('suggest_threshold');
    $highThreshold = (float) $config->get('high_threshold');
    $this->buildIndex();

    $storage = $this->entityTypeManager->getStorage('sunset_url');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('review', SunsetUrl::REVIEW_PENDING)
      ->sort('id');
    if ($host !== NULL) {
      $query->condition('host', $this->normalizer->canonicalHost($host));
    }
    if ($rematch) {
      $or = $query->orConditionGroup()
        ->notExists('method')
        ->condition('method', 'manual', '<>');
      $query->condition($or);
    }
    else {
      $query->notExists('method');
    }

    $stats = ['processed' => 0, 'exact' => 0, 'fuzzy' => 0, 'parent' => 0, 'none' => 0, 'high' => 0];
    $counter = ['slug_exact' => 'exact', 'slug_fuzzy' => 'fuzzy', 'parent' => 'parent'];
    foreach (array_chunk($query->execute(), 250) as $ids) {
      foreach ($storage->loadMultiple($ids) as $entity) {
        $match = $this->bestMatch(
          (string) $entity->get('lang')->value,
          (string) $entity->get('kind')->value,
          (string) $entity->get('section')->value,
          array_filter(explode(' ', (string) $entity->get('tokens')->value)),
          array_filter(explode(' ', (string) $entity->get('parent_tokens')->value)),
          $suggestThreshold,
        );
        if ($match !== NULL && $match['score'] >= $suggestThreshold) {
          $entity->set('target_url', $match['url']);
          $entity->set('confidence', round($match['score'], 3));
          $entity->set('method', $match['method']);
          $stats[$counter[$match['method']]]++;
          if ($match['score'] >= $highThreshold) {
            $stats['high']++;
          }
        }
        else {
          $entity->set('target_url', NULL);
          $entity->set('confidence', $match !== NULL ? round($match['score'], 3) : 0);
          $entity->set('method', 'none');
          $stats['none']++;
        }
        $entity->save();
        $stats['processed']++;
        if ($progress !== NULL && $stats['processed'] % 500 === 0) {
          $progress($stats);
        }
      }
      $storage->resetCache($ids);
    }
    return $stats;
  }

  /**
   * Finds the best target for one legacy URL.
   *
   * @param string $lang
   *   Language of the legacy URL ("und" counts as English).
   * @param string $kind
   *   Either "page" or "file".
   * @param string $section
   *   Legacy section.
   * @param string[] $tokens
   *   Slug words of the legacy URL.
   * @param string[] $parentTokens
   *   Slug words of its parent page, tried when the page itself finds no
   *   candidate above the threshold.
   * @param float $threshold
   *   Score under which the parent page is tried.
   *
   * @return array{url: string, score: float, method: string}|null
   *   The best candidate, or NULL without any candidate.
   */
  public function bestMatch(string $lang, string $kind, string $section, array $tokens, array $parentTokens = [], float $threshold = 0.6): ?array {
    $tokens = array_values(array_unique($tokens));
    $best = $this->bestForTokens($lang === 'fr' ? 'fr' : 'en', $kind, $section, $tokens);
    // French documents often kept English file names while their gtno.ca
    // record got a translated slug: the English record beats a search.
    if ($kind === 'file' && $lang === 'fr' && ($best === NULL || $best['score'] < $threshold)) {
      $english = $this->bestForTokens('en', $kind, $section, $tokens);
      if ($english !== NULL && $english['score'] * Scorer::CROSS_LANGUAGE_FACTOR > ($best['score'] ?? 0.0)) {
        $best = ['score' => $english['score'] * Scorer::CROSS_LANGUAGE_FACTOR] + $english;
      }
    }
    if (($best === NULL || $best['score'] < $threshold) && $parentTokens !== []) {
      $parent = $this->bestForTokens($lang === 'fr' ? 'fr' : 'en', 'page', $section, array_values(array_unique($parentTokens)));
      if ($parent !== NULL && $parent['score'] >= $threshold) {
        $score = $parent['score'] * Scorer::PARENT_FACTOR;
        if ($best === NULL || $score > $best['score']) {
          $best = ['url' => $parent['url'], 'score' => $score, 'method' => 'parent'];
        }
      }
    }
    return $best;
  }

  /**
   * Finds the best target for a set of slug words in one language.
   *
   * @return array{url: string, score: float, method: string}|null
   *   The best candidate, or NULL without any candidate.
   */
  private function bestForTokens(string $lang, string $kind, string $section, array $tokens): ?array {
    if ($tokens === [] || empty($this->documents[$lang])) {
      return NULL;
    }

    // 1. Same words in the slug, in any order.
    $exact = $this->exact[$lang][Scorer::key($tokens)] ?? [];
    if ($exact !== []) {
      $bonuses = [];
      foreach ($exact as $id) {
        $bonuses[$id] = Scorer::sectionBonus($kind, $section, $this->targets[$id]['section']);
      }
      arsort($bonuses);
      $bestId = array_key_first($bonuses);
      $values = array_values($bonuses);
      $score = match (TRUE) {
        count($exact) === 1 => Scorer::EXACT_UNIQUE,
        $values[0] > $values[1] => Scorer::EXACT_SHARED,
        default => Scorer::EXACT_AMBIGUOUS,
      };
      return ['url' => $this->targets[$bestId]['url'], 'score' => $score, 'method' => 'slug_exact'];
    }

    // 2. Pages sharing the rarest words, scored by weighted Jaccard.
    $known = array_filter($tokens, fn (string $token): bool => isset($this->postings[$lang][$token]));
    usort($known, fn (string $a, string $b): int => count($this->postings[$lang][$a]) <=> count($this->postings[$lang][$b]));
    $maxFrequency = max(50, (int) ($this->documents[$lang] * 0.05));
    $candidates = [];
    foreach (array_slice($known, 0, 4) as $token) {
      if (count($this->postings[$lang][$token]) > $maxFrequency) {
        continue;
      }
      foreach ($this->postings[$lang][$token] as $id) {
        $candidates[$id] = TRUE;
      }
    }
    if ($candidates === []) {
      return NULL;
    }

    $unknownIdf = Scorer::idf($this->documents[$lang], 1);
    $best = NULL;
    foreach (array_keys($candidates) as $id) {
      $target = $this->targets[$id];
      $score = Scorer::weightedJaccard($tokens, $target['tokens'], $this->idf[$lang], $unknownIdf)
        + Scorer::sectionBonus($kind, $section, $target['section']);
      $score = max(0.0, min(Scorer::FUZZY_CEILING, $score));
      if ($best === NULL || $score > $best['score']) {
        $best = ['url' => $target['url'], 'score' => $score, 'method' => 'slug_fuzzy'];
      }
    }
    return $best;
  }

  /**
   * Loads every target page into memory and indexes its slug words.
   */
  private function buildIndex(): void {
    $this->targets = $this->exact = $this->postings = $this->idf = $this->documents = [];
    $result = $this->database->select('sunset_target', 't')
      ->fields('t', ['id', 'url', 'lang', 'section', 'tokens'])
      ->execute();
    foreach ($result as $row) {
      $tokens = array_values(array_filter(explode(' ', (string) $row->tokens)));
      if ($tokens === []) {
        continue;
      }
      $id = (int) $row->id;
      $lang = (string) $row->lang;
      $this->targets[$id] = [
        'url' => (string) $row->url,
        'lang' => $lang,
        'section' => (string) $row->section,
        'tokens' => $tokens,
      ];
      $this->exact[$lang][Scorer::key($tokens)][] = $id;
      foreach ($tokens as $token) {
        $this->postings[$lang][$token][] = $id;
      }
      $this->documents[$lang] = ($this->documents[$lang] ?? 0) + 1;
    }
    foreach ($this->postings as $lang => $tokens) {
      foreach ($tokens as $token => $ids) {
        $this->idf[$lang][$token] = Scorer::idf($this->documents[$lang], count($ids));
      }
    }
  }

}
