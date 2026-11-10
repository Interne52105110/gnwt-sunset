<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset;

/**
 * A URL reduced to the parts the toolkit compares and looks up.
 */
final class NormalizedUrl {

  /**
   * Constructs a NormalizedUrl.
   *
   * @param string $url
   *   Absolute URL that still resolves (original host, encoded path).
   * @param string $host
   *   Canonical host: lowercase, no "www." prefix, no port.
   * @param string $path
   *   Decoded, lowercase path without trailing slash or query string.
   * @param string $lang
   *   Language: "en", "fr", or "und" when the path carries no prefix.
   * @param string $kind
   *   Either "page" or "file".
   * @param string $section
   *   First path segment after the language prefix ("files" for documents).
   * @param string $slug
   *   Last path segment, without extension for documents.
   * @param string[] $tokens
   *   Meaningful words of the slug, transliterated, without stop words.
   * @param string $hash
   *   Lookup key: sha1 of canonical host and normalized path.
   * @param string[] $parentTokens
   *   Slug words of the parent page, for sub-pages ("/en/a/b/c" → "b").
   */
  public function __construct(
    public readonly string $url,
    public readonly string $host,
    public readonly string $path,
    public readonly string $lang,
    public readonly string $kind,
    public readonly string $section,
    public readonly string $slug,
    public readonly array $tokens,
    public readonly string $hash,
    public readonly array $parentTokens = [],
  ) {}

}
