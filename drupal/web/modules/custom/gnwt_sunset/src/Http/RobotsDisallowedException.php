<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Http;

/**
 * Thrown when robots.txt asks crawlers to stay away from a URL.
 */
final class RobotsDisallowedException extends \RuntimeException {}
