<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Http;

/**
 * Thrown when a host keeps answering with server errors.
 *
 * The toolkit stops sending requests to that host for the rest of the run
 * instead of adding load to a server that is already struggling.
 */
final class HostUnavailableException extends \RuntimeException {}
