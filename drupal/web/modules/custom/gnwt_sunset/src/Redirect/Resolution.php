<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Redirect;

/**
 * Where a legacy request goes, and why.
 */
final class Resolution {

  /**
   * Constructs a Resolution.
   *
   * @param string $url
   *   Absolute destination.
   * @param int $status
   *   Either 301 for reviewed destinations or 302 for temporary fallbacks.
   * @param string $reason
   *   One of: home, approved, suggested, gone, unreviewed, unknown.
   */
  public function __construct(
    public readonly string $url,
    public readonly int $status,
    public readonly string $reason,
  ) {}

}
