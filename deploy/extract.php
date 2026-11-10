<?php

/**
 * @file
 * One-time extractor for shared hosting without a shell.
 *
 * Unpacks ../sunset-release.zip into the project folder in slices that stay
 * under the server's execution time limit, then deletes the archive and
 * itself. It only runs with the random key written into its file name.
 */

declare(strict_types=1);

// It runs before Drupal and its libraries exist on the server.
// phpcs:disable DrupalPractice.Variables.GetRequestData, DrupalPractice.Constants.GlobalConstant

const KEY = '__TOKEN__';
const ARCHIVE = __DIR__ . '/../sunset-release.zip';
const TARGET = __DIR__ . '/..';
const SLICE_SECONDS = 20;

if (!hash_equals(KEY, (string) ($_GET['k'] ?? ''))) {
  http_response_code(404);
  exit;
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

// Browser uploads are limited in size: the archive may arrive in numbered
// parts (sunset-release.zip.001, .002...). Join them once.
$parts = glob(ARCHIVE . '.[0-9][0-9][0-9]') ?: [];
if (!is_file(ARCHIVE) && $parts !== []) {
  sort($parts);
  $out = fopen(ARCHIVE, 'wb');
  foreach ($parts as $part) {
    $in = fopen($part, 'rb');
    stream_copy_to_stream($in, $out);
    fclose($in);
  }
  fclose($out);
  array_map('unlink', $parts);
}

$zip = new ZipArchive();
if ($zip->open(ARCHIVE) !== TRUE) {
  exit('Archive not found.');
}

$total = $zip->numFiles;
$start = max(0, (int) ($_GET['i'] ?? 0));
$began = microtime(TRUE);
for ($i = $start; $i < $total; $i++) {
  $name = (string) $zip->getNameIndex($i);
  // Refuse absolute paths and parent references.
  if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) {
    continue;
  }
  if (!$zip->extractTo(TARGET, $name)) {
    exit('Failed on ' . htmlspecialchars($name));
  }
  if (microtime(TRUE) - $began > SLICE_SECONDS) {
    $zip->close();
    $next = '?k=' . KEY . '&i=' . ($i + 1);
    header('Refresh: 1; url=' . $next);
    printf('Extracted %d of %d files… continuing.', $i + 1, $total);
    exit;
  }
}
$zip->close();
unlink(ARCHIVE);
unlink(__FILE__);
printf('Done: %d files extracted. The archive and this extractor were deleted.', $total);
