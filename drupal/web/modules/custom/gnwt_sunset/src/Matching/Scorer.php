<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Matching;

/**
 * Pure scoring functions, kept apart so they can be unit tested.
 */
final class Scorer {

  /**
   * Score of an exact slug match when it is the only candidate.
   */
  public const EXACT_UNIQUE = 0.97;

  /**
   * Score of an exact slug match shared by several pages.
   *
   * One of them sits in the expected section, so it is preferred.
   */
  public const EXACT_SHARED = 0.9;

  /**
   * Score of an exact slug match shared by several equally good pages.
   */
  public const EXACT_AMBIGUOUS = 0.75;

  /**
   * Factor applied when the parent page is suggested for a sub-page.
   */
  public const PARENT_FACTOR = 0.8;

  /**
   * Factor applied when a French document falls back to the English record.
   */
  public const CROSS_LANGUAGE_FACTOR = 0.8;

  /**
   * Ceiling of a fuzzy match, so it never outranks an exact one.
   */
  public const FUZZY_CEILING = 0.95;

  /**
   * Weighted Jaccard similarity of two token sets.
   *
   * Rare words weigh more than common ones ("tuberculosis" says more than
   * "program"), using inverse document frequency over the new site.
   *
   * @param string[] $a
   *   Tokens of the legacy URL.
   * @param string[] $b
   *   Tokens of a candidate page.
   * @param array<string, float> $idf
   *   Inverse document frequency per token.
   * @param float $unknownIdf
   *   Weight of a token absent from the new site.
   */
  public static function weightedJaccard(array $a, array $b, array $idf, float $unknownIdf): float {
    $a = array_unique($a);
    $b = array_unique($b);
    if ($a === [] || $b === []) {
      return 0.0;
    }
    $weight = static fn (string $token): float => $idf[$token] ?? $unknownIdf;
    $intersection = array_sum(array_map($weight, array_intersect($a, $b)));
    $union = array_sum(array_map($weight, array_unique(array_merge($a, $b))));
    return $union > 0 ? $intersection / $union : 0.0;
  }

  /**
   * Inverse document frequency of a token.
   */
  public static function idf(int $documents, int $documentFrequency): float {
    return log(1 + $documents / max(1, $documentFrequency));
  }

  /**
   * Order-insensitive key used for exact slug matches.
   *
   * @param string[] $tokens
   *   Tokens of a slug.
   */
  public static function key(array $tokens): string {
    $tokens = array_unique($tokens);
    sort($tokens);
    return implode(' ', $tokens);
  }

  /**
   * Adjusts a score for the kind of legacy URL and the target section.
   */
  public static function sectionBonus(string $kind, string $legacySection, string $targetSection): float {
    $isResource = in_array($targetSection, ['resources', 'ressources'], TRUE);
    if ($kind === 'file') {
      return $isResource ? 0.08 : 0.0;
    }
    $isLegacyNews = in_array($legacySection, ['newsroom', 'news', 'nouvelles', 'communiques'], TRUE);
    if ($isLegacyNews && in_array($targetSection, ['newsroom', 'nouvelles'], TRUE)) {
      return 0.08;
    }
    // A page seldom becomes a document record.
    return $isResource ? -0.05 : 0.0;
  }

}
