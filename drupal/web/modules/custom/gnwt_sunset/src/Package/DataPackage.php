<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Package;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;

/**
 * Moves inventory data between environments as a gzipped JSON-lines file.
 *
 * Shared hosting has no shell: the data is prepared locally with Drush, sent
 * with the code, then loaded through a Batch API form in small slices that
 * stay within the web server's execution time limit.
 */
final class DataPackage {

  /**
   * Tables exported, in import order.
   */
  public const TABLES = ['sunset_target', 'sunset_url'];

  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Writes both inventory tables to a package file.
   *
   * @return array<string, int>
   *   Rows written per table.
   */
  public function export(string $file): array {
    $handle = gzopen($file, 'wb6');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Cannot write %s.', $file));
    }
    $counts = [];
    foreach (self::TABLES as $table) {
      $counts[$table] = 0;
      $result = $this->database->select($table, 't')->fields('t')->orderBy('id')->execute();
      foreach ($result as $row) {
        $row = (array) $row;
        unset($row['id'], $row['uuid']);
        gzwrite($handle, json_encode(['t' => $table, 'r' => $row], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $counts[$table]++;
      }
    }
    gzclose($handle);
    return $counts;
  }

  /**
   * Counts the lines of a package.
   */
  public function count(string $file): int {
    $handle = $this->open($file);
    $lines = 0;
    while (gzgets($handle) !== FALSE) {
      $lines++;
    }
    gzclose($handle);
    return $lines;
  }

  /**
   * Imports one slice of a package.
   *
   * @return int
   *   Lines read.
   */
  public function importSlice(string $file, int $offset, int $length): int {
    $handle = $this->open($file);
    for ($i = 0; $i < $offset && gzgets($handle) !== FALSE; $i++) {
      // Skip lines imported by earlier slices.
    }
    $batches = [];
    $read = 0;
    while ($read < $length && ($line = gzgets($handle)) !== FALSE) {
      $read++;
      $item = json_decode($line, TRUE);
      if (!is_array($item) || !in_array($item['t'] ?? '', self::TABLES, TRUE)) {
        continue;
      }
      $item['r']['uuid'] = $this->uuid->generate();
      $batches[$item['t']][] = $item['r'];
    }
    gzclose($handle);

    $transaction = $this->database->startTransaction();
    foreach ($batches as $table => $rows) {
      foreach (array_chunk($rows, 250) as $chunk) {
        $insert = $this->database->insert($table)->fields(array_keys($chunk[0]));
        foreach ($chunk as $row) {
          $insert->values($row);
        }
        $insert->execute();
      }
    }
    unset($transaction);
    return $read;
  }

  /**
   * Empties both inventory tables.
   */
  public function clear(): void {
    foreach (array_reverse(self::TABLES) as $table) {
      $this->database->truncate($table)->execute();
    }
    $this->invalidate();
  }

  /**
   * Invalidates the caches that show inventory data.
   */
  public function invalidate(): void {
    Cache::invalidateTags(['sunset_url_list', 'sunset_target_list']);
  }

  /**
   * Number of rows already stored.
   */
  public function storedRows(): int {
    $total = 0;
    foreach (self::TABLES as $table) {
      $total += (int) $this->database->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField();
    }
    return $total;
  }

  /**
   * Opens a package for reading.
   *
   * @return resource
   *   A gzip stream.
   */
  private function open(string $file) {
    $handle = is_readable($file) ? gzopen($file, 'rb') : FALSE;
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Cannot read %s.', $file));
    }
    return $handle;
  }

}
