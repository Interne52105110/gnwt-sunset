<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows a 0–1 score as a number with a coloured bar.
 *
 * The number stays visible text, so the colour is never the only signal.
 */
#[FieldFormatter(
  id: 'gnwt_sunset_confidence',
  label: new TranslatableMarkup('Confidence bar'),
  field_types: ['float'],
)]
final class ConfidenceFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      if ($item->value === NULL) {
        continue;
      }
      $score = max(0.0, min(1.0, (float) $item->value));
      $level = match (TRUE) {
        $score >= 0.85 => 'high',
        $score >= 0.6 => 'medium',
        default => 'low',
      };
      $elements[$delta] = [
        '#type' => 'inline_template',
        '#template' => '<span class="confidence confidence--{{ level }}"><span class="confidence__value">{{ value }}</span><span class="confidence__bar" aria-hidden="true"><span style="width: {{ percent }}%"></span></span></span>',
        '#context' => [
          'level' => $level,
          'value' => number_format($score, 2),
          'percent' => (int) round($score * 100),
        ],
      ];
    }
    return $elements;
  }

}
