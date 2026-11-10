<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Utility\Html;

/**
 * Shows a list value as a pill whose class carries the machine value.
 */
#[FieldFormatter(
  id: 'gnwt_sunset_badge',
  label: new TranslatableMarkup('Badge'),
  field_types: ['list_string'],
)]
final class BadgeFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $allowed = $this->fieldDefinition->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
    $elements = [];
    foreach ($items as $delta => $item) {
      $value = (string) $item->value;
      if ($value === '') {
        continue;
      }
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $allowed[$value] ?? $value,
        '#attributes' => ['class' => ['pill', 'pill--' . Html::getClass($value)]],
      ];
    }
    return $elements;
  }

}
