<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings of the sunset toolkit.
 */
final class SettingsForm extends ConfigFormBase {

  private const CONFIG = 'gnwt_sunset.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gnwt_sunset_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $lines = static fn (string $value): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));

    $form['hosts'] = [
      '#type' => 'details',
      '#title' => $this->t('Hosts'),
      '#open' => TRUE,
    ];
    $form['hosts']['legacy_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Legacy hosts'),
      '#description' => $this->t('One per line, without "www.". Requests reaching this site under these names are redirected. Add them to trusted_host_patterns too.'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'legacy_hosts',
        static fn (array $hosts): string => implode("\n", $hosts),
        $lines,
      ),
    ];
    $form['hosts']['target_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('New site hosts'),
      '#description' => $this->t('One per line as host|langcode, for example gnwt.ca|en.'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'target_hosts',
        static fn (array $targets): string => implode("\n", array_map(static fn (array $t): string => $t['host'] . '|' . $t['langcode'], $targets)),
        static function (string $value) use ($lines): array {
          $targets = [];
          foreach ($lines($value) as $line) {
            [$host, $langcode] = array_map('trim', explode('|', $line, 2)) + [1 => 'en'];
            $targets[] = ['host' => $host, 'langcode' => $langcode];
          }
          return $targets;
        },
      ),
    ];

    $form['redirects'] = [
      '#type' => 'details',
      '#title' => $this->t('Redirects'),
      '#open' => TRUE,
    ];
    foreach (['en' => $this->t('English'), 'fr' => $this->t('French')] as $langcode => $language) {
      $form['redirects']['home_' . $langcode] = [
        '#type' => 'url',
        '#title' => $this->t('Home page (@language)', ['@language' => $language]),
        '#config_target' => self::CONFIG . ':home.' . $langcode,
      ];
      $form['redirects']['fallback_' . $langcode] = [
        '#type' => 'textfield',
        '#title' => $this->t('Fallback for unmapped URLs (@language)', ['@language' => $language]),
        '#description' => $this->t('Answered with a temporary 302. {keywords} is replaced by the words of the old slug.'),
        '#maxlength' => 512,
        '#config_target' => self::CONFIG . ':fallback.' . $langcode,
      ];
    }
    $form['redirects']['serve_high_confidence'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve unreviewed high-confidence suggestions with a temporary 302'),
      '#description' => $this->t('Off by default: only approved mappings get a permanent 301.'),
      '#config_target' => self::CONFIG . ':serve_high_confidence',
    ];

    $form['matching'] = [
      '#type' => 'details',
      '#title' => $this->t('Matching'),
      '#open' => TRUE,
    ];
    $form['matching']['suggest_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum score to suggest a page'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#config_target' => self::CONFIG . ':suggest_threshold',
    ];
    $form['matching']['high_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('High-confidence score'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#config_target' => self::CONFIG . ':high_threshold',
    ];

    $form['crawl'] = [
      '#type' => 'details',
      '#title' => $this->t('Crawl politeness'),
      '#open' => FALSE,
    ];
    $form['crawl']['user_agent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User agent'),
      '#config_target' => self::CONFIG . ':user_agent',
    ];
    $form['crawl']['min_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum seconds between two requests to one host'),
      '#description' => $this->t('robots.txt Crawl-delay wins when it is longer.'),
      '#min' => 0,
      '#step' => 0.1,
      '#config_target' => self::CONFIG . ':min_delay',
    ];
    $form['crawl']['max_consecutive_errors'] = [
      '#type' => 'number',
      '#title' => $this->t('Server errors in a row before a host is skipped'),
      '#min' => 1,
      '#config_target' => self::CONFIG . ':max_consecutive_errors',
    ];

    return parent::buildForm($form, $form_state);
  }

}
