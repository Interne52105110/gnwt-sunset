<?php

/**
 * @file
 * Checks the live site after a deployment, and warms its caches.
 *
 * Usage: php smoke-test.php <base-url>
 *
 * Each public page must answer with the right status, load its stylesheet
 * and carry its share card; the deployment receiver must stay silent to
 * unsigned callers.
 */

declare(strict_types=1);

$base = rtrim($argv[1] ?? '', '/');
if (!preg_match('#^https?://#', $base)) {
  fwrite(STDERR, "Usage: php smoke-test.php <base-url>\n");
  exit(2);
}

/**
 * Fetches a URL without following redirects.
 */
function fetch(string $url): array {
  $curl = curl_init($url);
  curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_FOLLOWLOCATION => FALSE,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HEADER => FALSE,
    CURLOPT_USERAGENT => 'sunset-smoke-test',
  ]);
  $body = (string) curl_exec($curl);
  $result = [
    'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
    'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
    'location' => (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL),
    'body' => $body,
  ];
  return $result;
}

/**
 * Resolves a link found in a page against the site.
 */
function absolute(string $base, string $href): string {
  $href = html_entity_decode($href);
  if (preg_match('#^https?://#', $href)) {
    return $href;
  }
  $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $base);
  return $origin . '/' . ltrim($href, '/');
}

$failures = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
  printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok || $detail === '' ? '' : " ($detail)");
  if (!$ok) {
    $failures[] = $label;
  }
};

$pages = [
  '/' => 'class="hero"',
  '/try' => '<form',
  '/try?url=' . rawurlencode('https://www.hss.gov.nt.ca/en/services') => 'class="result',
  '/legacy-urls' => 'class="confidence',
];
foreach ($pages as $path => $marker) {
  $page = fetch($base . $path);
  $check("GET $path answers 200", $page['status'] === 200, 'HTTP ' . $page['status']);
  if ($page['status'] !== 200) {
    continue;
  }
  $check("$path shows its content", str_contains($page['body'], $marker), "no $marker");
  preg_match_all('#<link rel="stylesheet"[^>]*href="([^"]+)"#', $page['body'], $sheets);
  $check("$path links a stylesheet", $sheets[1] !== []);
  foreach ($sheets[1] as $href) {
    $css = fetch(absolute($base, $href));
    $check("  stylesheet $href", $css['status'] === 200 && str_starts_with($css['type'], 'text/css'), 'HTTP ' . $css['status'] . ' ' . $css['type']);
  }
  if (preg_match('#<meta property="og:image" content="([^"]+)"#', $page['body'], $image)) {
    $card = fetch($image[1]);
    $check("  share card", $card['status'] === 200 && $card['type'] === 'image/png', 'HTTP ' . $card['status']);
  }
  else {
    $check("$path has a share card", FALSE);
  }
}

$old = fetch($base . '/sunset');
$check('GET /sunset redirects to the front page (301)', $old['status'] === 301 && rtrim($old['location'], '/') === $base, 'HTTP ' . $old['status'] . ' ' . $old['location']);

$missing = fetch($base . '/no-such-page-' . bin2hex(random_bytes(4)));
$check('Unknown page answers 404', $missing['status'] === 404, 'HTTP ' . $missing['status']);

$receiver = fetch($base . '/_deploy.php?action=manifest');
$check('Deployment receiver ignores unsigned calls', $receiver['status'] === 404, 'HTTP ' . $receiver['status']);

if ($failures !== []) {
  fwrite(STDERR, count($failures) . " check(s) failed.\n");
  exit(1);
}
echo "All checks passed.\n";
