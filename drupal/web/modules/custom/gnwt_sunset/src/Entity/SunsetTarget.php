<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\SunsetStorageSchema;
use Drupal\views\EntityViewsData;

/**
 * A page of the new site, read from its XML sitemap.
 */
#[ContentEntityType(
  id: 'sunset_target',
  label: new TranslatableMarkup('GNWT.ca page'),
  label_collection: new TranslatableMarkup('GNWT.ca pages'),
  label_singular: new TranslatableMarkup('GNWT.ca page'),
  label_plural: new TranslatableMarkup('GNWT.ca pages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'url',
  ],
  handlers: [
    'storage_schema' => SunsetStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer gnwt sunset',
  base_table: 'sunset_target',
  label_count: [
    'singular' => '@count GNWT.ca page',
    'plural' => '@count GNWT.ca pages',
  ],
)]
final class SunsetTarget extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('URL'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048);

    $fields['url_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Lookup key'))
      ->setSetting('max_length', 40);

    $fields['host'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Host'))
      ->setSetting('max_length', 255);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Normalized path'))
      ->setSetting('max_length', 2048);

    $fields['lang'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Language'))
      ->setSetting('max_length', 12);

    $fields['section'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Section'))
      ->setSetting('max_length', 255);

    $fields['slug'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Slug'))
      ->setSetting('max_length', 1024);

    $fields['tokens'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Slug words'))
      ->setSetting('max_length', 1024);

    $fields['alternate_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Other-language version'))
      ->setSetting('max_length', 2048);

    $fields['lastmod'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last modified (sitemap)'));

    return $fields;
  }

}
