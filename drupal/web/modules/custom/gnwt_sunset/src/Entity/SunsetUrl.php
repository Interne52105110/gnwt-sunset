<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\Form\SunsetUrlForm;
use Drupal\gnwt_sunset\SunsetStorageSchema;
use Drupal\gnwt_sunset\SunsetUrlViewsData;

/**
 * A URL of a legacy gov.nt.ca site and the GNWT.ca page it should lead to.
 */
#[ContentEntityType(
  id: 'sunset_url',
  label: new TranslatableMarkup('Legacy URL'),
  label_collection: new TranslatableMarkup('Legacy URLs'),
  label_singular: new TranslatableMarkup('legacy URL'),
  label_plural: new TranslatableMarkup('legacy URLs'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'url',
  ],
  handlers: [
    'storage_schema' => SunsetStorageSchema::class,
    'views_data' => SunsetUrlViewsData::class,
    'form' => [
      'default' => SunsetUrlForm::class,
      'edit' => SunsetUrlForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'edit-form' => '/admin/content/sunset/{sunset_url}/edit',
  ],
  admin_permission: 'review gnwt sunset mappings',
  base_table: 'sunset_url',
  label_count: [
    'singular' => '@count legacy URL',
    'plural' => '@count legacy URLs',
  ],
)]
final class SunsetUrl extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * Review states.
   */
  public const REVIEW_PENDING = 'pending';
  public const REVIEW_APPROVED = 'approved';
  public const REVIEW_GONE = 'gone';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Legacy URL'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('view', TRUE);

    $fields['url_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Lookup key'))
      ->setDescription(new TranslatableMarkup('sha1 of the canonical host and the normalized path.'))
      ->setSetting('max_length', 40);

    $fields['host'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Legacy host'))
      ->setSetting('max_length', 255);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Normalized path'))
      ->setSetting('max_length', 2048);

    $fields['lang'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Language'))
      ->setSetting('max_length', 12);

    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setSetting('allowed_values', [
        'page' => 'Page',
        'file' => 'Document',
      ]);

    $fields['section'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Section'))
      ->setSetting('max_length', 255);

    $fields['tokens'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Slug words'))
      ->setSetting('max_length', 1024);

    $fields['parent_tokens'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Parent slug words'))
      ->setDescription(new TranslatableMarkup('Used when a sub-page was merged into its parent on the new site.'))
      ->setSetting('max_length', 1024);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Discovered through'))
      ->setSetting('max_length', 32);

    $fields['first_seen'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('First archived'));

    $fields['last_seen'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last archived'));

    $fields['target_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('GNWT.ca page'))
      ->setDescription(new TranslatableMarkup('Absolute URL of the page that replaces this one. Changing it marks the mapping as manual.'))
      ->setSetting('max_length', 2048)
      ->addPropertyConstraints('value', [
        'Regex' => [
          'pattern' => '#^https://#',
          'message' => new TranslatableMarkup('The target must be an absolute https:// URL.'),
        ],
      ])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('view', TRUE);

    $fields['confidence'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Confidence'));

    $fields['method'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Match method'))
      ->setSetting('allowed_values', [
        'slug_exact' => 'Same slug',
        'slug_fuzzy' => 'Similar slug',
        'parent' => 'Parent page (content merged)',
        'manual' => 'Set by an editor',
        'none' => 'No candidate',
      ]);

    $fields['review'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Review'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::REVIEW_PENDING)
      ->setSetting('allowed_values', [
        self::REVIEW_PENDING => 'To review',
        self::REVIEW_APPROVED => 'Approved',
        self::REVIEW_GONE => 'No equivalent',
      ])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 5]);

    $fields['live_status'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Legacy status'))
      ->setDescription(new TranslatableMarkup('HTTP status of the legacy URL at the last check (0 when unreachable).'));

    $fields['live_location'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Legacy redirects to'))
      ->setSetting('max_length', 2048);

    $fields['target_status'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target status'));

    $fields['checked'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last checked'));

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Reviewer notes'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 10]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    // Derive the lookup fields whenever the URL is new or edited, so that
    // records created by Migrate, the UI or Drush behave the same.
    if ($this->isNew() || $this->get('url')->value !== $this->getOriginal()?->get('url')->value) {
      $normalized = \Drupal::service('gnwt_sunset.url_normalizer')->normalize((string) $this->get('url')->value);
      if ($normalized !== NULL) {
        $this->set('url', $normalized->url);
        $this->set('url_hash', $normalized->hash);
        $this->set('host', $normalized->host);
        $this->set('path', $normalized->path);
        $this->set('lang', $normalized->lang);
        $this->set('kind', $normalized->kind);
        $this->set('section', $normalized->section);
        $this->set('tokens', implode(' ', $normalized->tokens));
        $this->set('parent_tokens', implode(' ', $normalized->parentTokens));
      }
    }
  }

}
