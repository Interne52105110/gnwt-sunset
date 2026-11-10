<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows a URL as a short, readable link.
 *
 * Legacy URLs all share a host, so only the path is shown; targets keep the
 * host because gnwt.ca and gtno.ca differ. The full URL stays in the link and
 * in its title attribute.
 */
#[FieldFormatter(
  id: 'gnwt_sunset_compact_url',
  label: new TranslatableMarkup('Compact link'),
  field_types: ['string'],
)]
final class CompactUrlFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return ['strip' => 'scheme'] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    return [
      'strip' => [
        '#type' => 'radios',
        '#title' => $this->t('Hide'),
        '#options' => [
          'scheme' => $this->t('The scheme (https://)'),
          'host' => $this->t('The scheme and the host'),
        ],
        '#default_value' => $this->getSetting('strip'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [$this->getSetting('strip') === 'host' ? $this->t('Path only') : $this->t('Host and path')];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $url = (string) $item->value;
      if ($url === '' || !preg_match('#^https?://#', $url)) {
        continue;
      }
      $parts = parse_url($url);
      $path = rawurldecode($parts['path'] ?? '/');
      $text = $this->getSetting('strip') === 'host' ? $path : ($parts['host'] ?? '') . $path;
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $text,
        '#attributes' => [
          'href' => $url,
          'title' => $url,
          'class' => ['compact-url'],
          'rel' => 'nofollow',
        ],
      ];
    }
    return $elements;
  }

}
