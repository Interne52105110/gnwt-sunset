<?php

/**
 * @file
 * Deployment receiver for shared hosting without a shell.
 *
 * The CI pipeline signs every request with an Ed25519 private key kept in
 * GitHub secrets. This file only holds the public half: it can check a
 * request, never forge one. Unsigned or stale requests get a plain 404.
 *
 * Steps, called in order by deploy/push.php:
 * - manifest: returns the checksums of the files deployed last time;
 * - upload: stores one part of the archive of changed files, or the plan;
 * - extract: unpacks the archive in slices that stay under the time limit;
 * - apply: deletes files that left the codebase;
 * - rebuild: rebuilds the service container and flushes caches;
 * - update: runs pending database updates, like "drush updatedb";
 * - finish: records the new manifest and removes the temporary files.
 *
 * It never writes under web/sites: settings and uploads belong to the server.
 */

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

// Filled in by deploy/build-dist.sh from deploy/deploy-key.pub.
$public_key = base64_decode('__PUBLIC_KEY__', TRUE);
$max_clock_skew = 300;
$slice_seconds = 20;
$update_seconds = 120;

$project = dirname(__DIR__);
$work = $project . '/.deploy';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/**
 * Sends a JSON answer and stops.
 */
function deploy_reply(int $status, array $data): never {
  if (!headers_sent()) {
    http_response_code($status);
  }
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Tells whether a relative path may be written or deleted by a deployment.
 */
function deploy_safe_path(string $path): bool {
  return $path !== ''
    && !str_starts_with($path, '/')
    && !str_contains($path, '\\')
    && !str_contains($path, "\0")
    && !preg_match('#(^|/)\.\.?(/|$)#', $path)
    && !str_starts_with($path, 'web/sites/')
    && !str_starts_with($path, '.deploy/');
}

/**
 * Deletes a file or a folder tree.
 */
function deploy_remove(string $path): void {
  if (is_dir($path) && !is_link($path)) {
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry !== '.' && $entry !== '..') {
        deploy_remove($path . '/' . $entry);
      }
    }
    rmdir($path);
  }
  elseif (file_exists($path) || is_link($path)) {
    unlink($path);
  }
}

/**
 * Boots Drupal the way index.php does, for the update step.
 */
function deploy_boot_drupal(object $autoloader, Request $request): DrupalKernel {
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();
  $kernel->preHandle($request);
  return $kernel;
}

// A fatal error still answers in JSON, so the pipeline can show it.
register_shutdown_function(static function (): void {
  $error = error_get_last();
  if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], TRUE)) {
    deploy_reply(500, ['error' => $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']]);
  }
});

// Check the signature before anything else.
$autoloader = require __DIR__ . '/autoload.php';
$request = Request::createFromGlobals();
$action = (string) $request->query->get('action', '');
$id = (string) $request->query->get('id', '');
$arg = (string) $request->query->get('arg', '');
$time = (int) $request->headers->get('X-Deploy-Time', '0');
$signature = base64_decode((string) $request->headers->get('X-Deploy-Signature', ''), TRUE);
$body = $request->getContent();
$message = implode("\n", [$action, $id, $arg, hash('sha256', $body), $time, $request->getHttpHost()]);

if (!function_exists('sodium_crypto_sign_verify_detached') || strlen((string) $public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
  deploy_reply(503, ['error' => 'Receiver not configured.']);
}
if ($signature === FALSE
  || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
  || abs(time() - $time) > $max_clock_skew
  || !sodium_crypto_sign_verify_detached($signature, $message, $public_key)) {
  deploy_reply(404, ['error' => 'Not found.']);
}
if ($action !== 'manifest' && !preg_match('/^[a-f0-9]{24}$/', $id)) {
  deploy_reply(400, ['error' => 'Bad deployment id.']);
}

if (!is_dir($work) && !mkdir($work, 0700, TRUE)) {
  deploy_reply(500, ['error' => 'Cannot create the work folder.']);
}
$archive = "$work/$id.zip";
$plan = "$work/$id.json";

try {
  switch ($action) {
    case 'manifest':
      $manifest = is_file("$work/manifest.json") ? json_decode((string) file_get_contents("$work/manifest.json"), TRUE) : [];
      deploy_reply(200, [
        'manifest' => $manifest ?: new stdClass(),
        'php' => PHP_VERSION,
        'last' => is_file("$work/last.json") ? json_decode((string) file_get_contents("$work/last.json"), TRUE) : NULL,
      ]);

    case 'upload':
      if ($arg === 'plan') {
        json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
        file_put_contents($plan, $body);
      }
      elseif (ctype_digit($arg)) {
        file_put_contents(sprintf('%s.%03d', $archive, (int) $arg), $body);
      }
      else {
        deploy_reply(400, ['error' => 'Bad part.']);
      }
      deploy_reply(200, ['stored' => strlen($body)]);

    case 'extract':
      // The archive arrives in parts, to stay under the request size limit.
      $parts = glob($archive . '.[0-9][0-9][0-9]') ?: [];
      if ($parts !== []) {
        sort($parts);
        $out = fopen($archive, 'wb');
        foreach ($parts as $part) {
          $in = fopen($part, 'rb');
          stream_copy_to_stream($in, $out);
          fclose($in);
        }
        fclose($out);
        array_map('unlink', $parts);
      }
      $zip = new ZipArchive();
      if ($zip->open($archive) !== TRUE) {
        deploy_reply(409, ['error' => 'Archive not found.']);
      }
      $total = $zip->numFiles;
      $began = microtime(TRUE);
      for ($i = max(0, (int) $arg); $i < $total; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!deploy_safe_path($name)) {
          deploy_reply(400, ['error' => "Refused path: $name"]);
        }
        if (!$zip->extractTo($project, $name)) {
          deploy_reply(500, ['error' => "Could not write $name"]);
        }
        if (microtime(TRUE) - $began > $slice_seconds) {
          deploy_reply(200, ['next' => $i + 1, 'total' => $total]);
        }
      }
      $zip->close();
      deploy_reply(200, ['done' => TRUE, 'total' => $total]);

    case 'apply':
      $data = json_decode((string) file_get_contents($plan), TRUE, 512, JSON_THROW_ON_ERROR);
      $deleted = 0;
      foreach (array_merge($data['delete'] ?? [], $data['obsolete'] ?? []) as $path) {
        if (!deploy_safe_path((string) $path) || !file_exists("$project/$path")) {
          continue;
        }
        deploy_remove("$project/$path");
        $deleted++;
        // Remove folders left empty, up to the project root.
        for ($dir = dirname("$project/$path"); $dir !== $project && is_dir($dir) && count(scandir($dir) ?: []) === 2; $dir = dirname($dir)) {
          rmdir($dir);
        }
      }
      deploy_reply(200, ['deleted' => $deleted]);

    case 'rebuild':
      // Same as core/rebuild.php: works even when the cached container is
      // out of date with the new code.
      chdir(__DIR__);
      require_once __DIR__ . '/core/includes/utility.inc';
      DrupalKernel::bootEnvironment();
      Settings::initialize(__DIR__, DrupalKernel::findSitePath($request), $autoloader);
      drupal_rebuild($autoloader, $request);
      deploy_reply(200, ['rebuilt' => TRUE]);

    case 'update':
      // Same order as "drush updatedb": schema updates, a rebuild, then
      // post-updates, with the site in maintenance mode meanwhile.
      chdir(__DIR__);
      $kernel = deploy_boot_drupal($autoloader, $request);
      require_once __DIR__ . '/core/includes/install.inc';
      require_once __DIR__ . '/core/includes/update.inc';
      drupal_load_updates();

      $start = [];
      foreach (update_get_update_list() as $module => $info) {
        if (!empty($info['start'])) {
          $start[$module] = $info['start'];
        }
      }
      $postUpdates = \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions();
      if ($start === [] && $postUpdates === []) {
        deploy_reply(200, ['ran' => []]);
      }

      $ran = [];
      $began = microtime(TRUE);
      $context = ['sandbox' => [], 'results' => [], 'finished' => 1, 'message' => ''];
      $maintenance = \Drupal::state()->get('system.maintenance_mode');
      \Drupal::state()->set('system.maintenance_mode', TRUE);
      if (!defined('MAINTENANCE_MODE')) {
        define('MAINTENANCE_MODE', 'update');
      }
      try {
        if ($start !== []) {
          $updates = update_resolve_dependencies($start);
          $map = [];
          foreach ($updates as $function => $update) {
            $map[$function] = empty($update['reverse_paths']) ? [] : array_keys($update['reverse_paths']);
          }
          foreach ($updates as $function => $update) {
            if (!$update['allowed']) {
              throw new RuntimeException("$function cannot run: missing " . implode(', ', $update['missing_dependencies'] ?? []));
            }
            $module = $update['module'];
            if (isset($start[$module])) {
              \Drupal::service('update.update_hook_registry')->setInstalledVersion($module, $update['number'] - 1);
              unset($start[$module]);
            }
            $context['sandbox'] = [];
            do {
              $context['finished'] = 1;
              update_do_one($module, $update['number'], $map[$function], $context);
              if (microtime(TRUE) - $began > $update_seconds) {
                throw new RuntimeException("$function takes too long here: finish it with /update.php.");
              }
            } while ($context['finished'] < 1 && empty($context['results']['#abort']));
            if (!empty($context['results']['#abort'])) {
              throw new RuntimeException("$function failed: " . (string) ($context['results'][$module][$update['number']]['#abort']['query'] ?? 'see the site log'));
            }
            $ran[] = $function;
          }
          drupal_flush_all_caches();
          $kernel->rebuildContainer();
          \Drupal::moduleHandler()->loadAll();
          \Drupal::moduleHandler()->invokeAll('rebuild');
        }
        foreach (\Drupal::service('update.post_update_registry')->getPendingUpdateFunctions() as $function) {
          $context['sandbox'] = [];
          do {
            $context['finished'] = 1;
            update_invoke_post_update($function, $context);
          } while ($context['finished'] < 1 && empty($context['results']['#abort']));
          if (!empty($context['results']['#abort'])) {
            throw new RuntimeException("$function failed: see the site log.");
          }
          $ran[] = $function;
        }
      }
      finally {
        \Drupal::state()->set('system.maintenance_mode', $maintenance);
      }
      deploy_reply(200, ['ran' => $ran]);

    case 'finish':
      $data = json_decode((string) file_get_contents($plan), TRUE, 512, JSON_THROW_ON_ERROR);
      file_put_contents("$work/manifest.json", json_encode($data['manifest'], JSON_UNESCAPED_SLASHES));
      file_put_contents("$work/last.json", json_encode([
        'id' => $id,
        'commit' => $data['commit'] ?? NULL,
        'time' => gmdate('c'),
        'files' => count($data['manifest']),
      ], JSON_UNESCAPED_SLASHES));
      array_map('unlink', glob("$work/$id.*") ?: []);
      deploy_reply(200, ['finished' => TRUE]);

    default:
      deploy_reply(400, ['error' => 'Unknown action.']);
  }
}
catch (Throwable $e) {
  deploy_reply(500, ['error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
}
