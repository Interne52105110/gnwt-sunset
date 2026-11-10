<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset;

use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Turns legacy and new URLs into comparable, hashable keys.
 *
 * Old Drupal 7 sites answer the same page under many variants (trailing
 * slash, upper case, facets in the query string, "www." or not). The
 * normalizer collapses those variants so one legacy page gets one record
 * and one redirect.
 */
final class UrlNormalizer {

  /**
   * File extensions treated as documents rather than pages.
   */
  public const FILE_EXTENSIONS = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'rtf', 'txt', 'zip',
  ];

  /**
   * Paths that never need a redirect of their own.
   */
  private const EXCLUDED = [
    // Drupal system and account paths, with or without a language prefix.
    '#^(?:/[a-z]{2})?/(?:user|admin|batch|cron|filter|search|system|node/\d+/(?:edit|delete|revisions))(?:/|$)#',
    // Code and theme assets.
    '#^/(?:misc|modules|themes|core|profiles|libraries)/#',
    '#^/sites/[^/]+/(?:modules|themes|libraries)/#',
    '#^/sites/[^/]+/files/(?:styles|css|js|languages|ctools|xmlsitemap|advagg_[a-z]+)/#',
    // Feeds and printer-friendly copies.
    '#/(?:feed|rss\.xml|print)(?:/|$)#',
    // Static assets.
    '#\.(?:css|js|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|map|xml|json)$#',
  ];

  /**
   * Words that carry no meaning for matching.
   */
  private const STOPWORDS = [
    // English.
    'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in',
    'is', 'it', 'of', 'on', 'or', 'our', 'the', 'to', 'with', 'your',
    // French.
    'au', 'aux', 'ce', 'd', 'dans', 'de', 'des', 'du', 'et', 'l', 'la', 'le',
    'les', 'par', 'pour', 'sur', 'un', 'une', 'vos', 'votre',
    // Drupal and file-name noise.
    'content', 'node', 'page', 'final', 'web', 'en', 'fr', 'eng', 'fra',
  ];

  public function __construct(
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * Returns the comparable form of a URL, or NULL when it is excluded.
   */
  public function normalize(string $url): ?NormalizedUrl {
    $parts = parse_url(trim($url));
    if ($parts === FALSE || empty($parts['host'])) {
      return NULL;
    }
    $path = $this->normalizePath($parts['path'] ?? '/');
    if ($this->isExcluded($path)) {
      return NULL;
    }
    [$lang, $content] = $this->splitLanguage($path);

    $last = $content === [] ? '' : $content[array_key_last($content)];
    $extension = strtolower(pathinfo($last, PATHINFO_EXTENSION));
    $kind = in_array($extension, self::FILE_EXTENSIONS, TRUE) ? 'file' : 'page';
    $slug = $kind === 'file' ? pathinfo($last, PATHINFO_FILENAME) : $last;
    $section = $kind === 'file' ? 'files' : ($content[0] ?? '');
    // Parent page slug: sub-pages are often merged into their parent.
    $parent = ($kind === 'page' && count($content) > 2) ? $content[count($content) - 2] : '';
    // Documents carry their language in the file name ("guide-fr_0.pdf").
    if ($kind === 'file' && $lang === 'und') {
      $lang = preg_match('/[-_](fr|fra|francais)(?:[-_]\d+)?$/', $slug) ? 'fr' : 'und';
    }

    $scheme = strtolower($parts['scheme'] ?? 'https');
    $originalHost = strtolower(preg_replace('/:\d+$/', '', $parts['host']));
    $host = $this->canonicalHost($parts['host']);

    return new NormalizedUrl(
      url: $scheme . '://' . $originalHost . $this->encodePath($path),
      host: $host,
      path: $path,
      lang: $lang,
      kind: $kind,
      section: $section,
      slug: $slug,
      tokens: $this->slugTokens($slug, $kind),
      hash: $this->hash($host, $path),
      parentTokens: $parent === '' ? [] : $this->slugTokens($parent, 'page'),
    );
  }

  /**
   * Tokens of a slug, without identifiers that carry no meaning.
   *
   * @return string[]
   *   Slug words.
   */
  public function slugTokens(string $slug, string $kind): array {
    // "/node/1234" says nothing about the page.
    if (ctype_digit($slug)) {
      return [];
    }
    // Drupal appends "_0", "_1"... to duplicate file names.
    if ($kind === 'file') {
      $slug = (string) preg_replace('/_\d+$/', '', $slug);
    }
    return $this->tokenize($slug);
  }

  /**
   * Lowercases a host and strips the port and the "www." prefix.
   */
  public function canonicalHost(string $host): string {
    $host = strtolower(trim($host));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
  }

  /**
   * Decodes, lowercases and tidies a path; drops the trailing slash.
   */
  public function normalizePath(string $path): string {
    $path = mb_strtolower(rawurldecode($path));
    $path = (string) preg_replace('#/{2,}#', '/', $path);
    $path = '/' . ltrim($path, '/');
    return $path === '/' ? $path : rtrim($path, '/');
  }

  /**
   * Splits a normalized path into its language and its content segments.
   *
   * Handles "/en/..." and department prefixes such as "/ecc/en/...".
   *
   * @return array{0: string, 1: string[]}
   *   The language code ("und" when absent) and the remaining segments.
   */
  public function splitLanguage(string $path): array {
    $segments = array_values(array_filter(explode('/', $path), 'strlen'));
    foreach (array_slice($segments, 0, 2) as $index => $segment) {
      if ($segment === 'en' || $segment === 'fr') {
        return [$segment, array_slice($segments, $index + 1)];
      }
    }
    return ['und', $segments];
  }

  /**
   * Whether a normalized path is a system path or an asset.
   */
  public function isExcluded(string $path): bool {
    foreach (self::EXCLUDED as $pattern) {
      if (preg_match($pattern, $path)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Splits a slug or a title into meaningful, transliterated words.
   *
   * Numbers are kept ("daily-update-september-16" must not match "-1"),
   * and a final "s" is dropped so that singular and plural meet.
   *
   * @return string[]
   *   Unique words, in order of appearance.
   */
  public function tokenize(string $text): array {
    $text = strtolower($this->transliteration->transliterate($text, 'en', ''));
    $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = [];
    foreach ($words as $word) {
      if (in_array($word, self::STOPWORDS, TRUE)) {
        continue;
      }
      $word = self::singular($word);
      $tokens[$word] = $word;
    }
    return array_values($tokens);
  }

  /**
   * Naive singular: drops a final "s" from longer words ("sheets" → "sheet").
   */
  public static function singular(string $word): string {
    if (strlen($word) > 3 && str_ends_with($word, 's') && !preg_match('/(?:ss|us|is)$/', $word)) {
      return substr($word, 0, -1);
    }
    return $word;
  }

  /**
   * Returns the lookup key of a canonical host and a normalized path.
   */
  public function hash(string $host, string $path): string {
    return sha1($host . $path);
  }

  /**
   * Encodes each path segment so the stored URL stays valid.
   */
  private function encodePath(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
  }

}
