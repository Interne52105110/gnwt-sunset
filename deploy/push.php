<?php

/**
 * @file
 * Sends a built tree to the deployment receiver (deploy/receiver.php).
 *
 * Usage: php push.php <dist-folder> <receiver-url> [--force] [--dry-run]
 * Environment: DEPLOY_PRIVATE_KEY (base64 Ed25519 secret key),
 *   DEPLOY_COMMIT (optional, recorded on the server).
 *
 * Only files whose checksum changed since the last deployment are sent.
 * Files that left the codebase are deleted, so are the paths listed in
 * deploy/obsolete-paths.txt. Then caches are rebuilt and database updates run.
 */

declare(strict_types=1);

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$force = in_array('--force', $argv, TRUE);
$dryRun = in_array('--dry-run', $argv, TRUE);
[$dist, $url] = $args + ['', ''];
if (!is_dir($dist) || !preg_match('#^https?://#', $url)) {
  fwrite(STDERR, "Usage: php push.php <dist-folder> <receiver-url> [--force] [--dry-run]\n");
  exit(2);
}
$secret = base64_decode((string) getenv('DEPLOY_PRIVATE_KEY'), TRUE);
if ($secret === FALSE || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
  fwrite(STDERR, "DEPLOY_PRIVATE_KEY is missing or invalid.\n");
  exit(2);
}
$parts = parse_url($url);
$target = [
  'url' => $url,
  'host' => $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
  'secret' => $secret,
];
// Parts stay under the server's request size limit.
$part_bytes = 4 * 1024 * 1024;

/**
 * Calls one step of the receiver with a signed request.
 */
function call(array $target, string $action, string $id = '', string $arg = '', string $body = ''): array {
  $time = time();
  $message = implode("\n", [$action, $id, $arg, hash('sha256', $body), $time, $target['host']]);
  $curl = curl_init($target['url'] . '?' . http_build_query(['action' => $action, 'id' => $id, 'arg' => $arg]));
  curl_setopt_array($curl, [
    CURLOPT_POST => TRUE,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/octet-stream',
      'X-Deploy-Time: ' . $time,
      'X-Deploy-Signature: ' . base64_encode(sodium_crypto_sign_detached($message, $target['secret'])),
    ],
  ]);
  $response = curl_exec($curl);
  $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
  if ($response === FALSE) {
    throw new RuntimeException("$action: " . curl_error($curl));
  }
  $data = json_decode((string) $response, TRUE);
  if ($status !== 200 || !is_array($data)) {
    $detail = is_array($data) ? ($data['error'] ?? $response) : substr((string) $response, 0, 500);
    throw new RuntimeException("$action failed (HTTP $status): $detail");
  }
  return $data;
}

/**
 * Lists the files of the tree with their checksums.
 *
 * Server-owned paths are left out: web/sites holds settings.php and uploads.
 */
function scan(string $root): array {
  $files = [];
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if (!$file->isFile()) {
      continue;
    }
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!str_starts_with($path, 'web/sites/')) {
      $files[$path] = sha1_file($file->getPathname());
    }
  }
  ksort($files);
  return $files;
}

try {
  $dist = rtrim(realpath($dist), '/\\');
  $local = scan($dist);
  $remote = call($target, 'manifest');
  $previous = $remote['manifest'] ?? [];
  printf("Server: PHP %s, last deployment %s\n", $remote['php'] ?? '?', $remote['last']['commit'] ?? 'unknown');

  $changed = array_keys(array_diff_assoc($local, $previous));
  $deleted = array_keys(array_diff_key($previous, $local));
  $obsoleteFile = __DIR__ . '/obsolete-paths.txt';
  $obsolete = is_file($obsoleteFile)
    ? array_values(array_filter(array_map('trim', file($obsoleteFile)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')))
    : [];
  printf("%d files in the build, %d to send, %d to delete%s\n", count($local), count($changed), count($deleted), $previous === [] ? ' (first deployment: full upload)' : '');
  foreach (array_slice($changed, 0, 30) as $path) {
    echo "  + $path\n";
  }
  foreach (array_slice($deleted, 0, 30) as $path) {
    echo "  - $path\n";
  }
  if ($dryRun) {
    exit(0);
  }
  if ($changed === [] && $deleted === [] && !$force) {
    echo "Nothing to deploy.\n";
    exit(0);
  }

  $id = bin2hex(random_bytes(12));
  call($target, 'upload', $id, 'plan', json_encode([
    'commit' => getenv('DEPLOY_COMMIT') ?: NULL,
    'delete' => $deleted,
    'obsolete' => $obsolete,
    'manifest' => $local,
  ], JSON_UNESCAPED_SLASHES));

  if ($changed !== []) {
    $zipPath = sys_get_temp_dir() . "/deploy-$id.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($changed as $path) {
      $zip->addFile("$dist/$path", $path);
    }
    $zip->close();
    printf("Archive: %.1f MB\n", filesize($zipPath) / 1048576);

    $handle = fopen($zipPath, 'rb');
    for ($part = 0; ($chunk = (string) fread($handle, $part_bytes)) !== ''; $part++) {
      call($target, 'upload', $id, (string) $part, $chunk);
    }
    fclose($handle);
    unlink($zipPath);
    echo "Uploaded.\n";

    $next = 0;
    do {
      $step = call($target, 'extract', $id, (string) $next);
      $next = (int) ($step['next'] ?? $step['total']);
      printf("Extracted %d of %d files\n", $next, $step['total']);
    } while (empty($step['done']));
  }

  $applied = call($target, 'apply', $id);
  printf("Deleted %d paths\n", $applied['deleted']);
  // Let PHP's opcode cache notice the new files before Drupal boots.
  sleep(3);
  call($target, 'rebuild', $id);
  echo "Caches rebuilt.\n";
  $updates = call($target, 'update', $id);
  if ($updates['ran'] !== []) {
    echo "Database updates: " . implode(', ', $updates['ran']) . "\n";
    call($target, 'rebuild', $id);
    echo "Caches rebuilt again.\n";
  }
  else {
    echo "No database updates.\n";
  }
  call($target, 'finish', $id);
  echo "Deployed.\n";
}
catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
