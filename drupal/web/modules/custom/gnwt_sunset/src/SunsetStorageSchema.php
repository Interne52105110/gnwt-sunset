<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the lookup indexes used by imports, matching and redirects.
 */
final class SunsetStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $this->storage->getBaseTable();

    // Every redirect is one lookup on this key: it must be unique and fast.
    $schema[$table]['unique keys'][$table . '__url_hash'] = ['url_hash'];
    $schema[$table]['indexes'][$table . '__host_lang'] = ['host', 'lang'];
    if ($entity_type->id() === 'sunset_url') {
      $schema[$table]['indexes'][$table . '__host_review'] = ['host', 'review'];
    }
    return $schema;
  }

}
