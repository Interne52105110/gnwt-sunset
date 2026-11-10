<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\Package\DataPackage;

/**
 * Loads a data package through the Batch API (no shell needed).
 */
final class ImportForm extends FormBase {

  /**
   * Lines imported per batch request.
   */
  private const SLICE = 2000;

  public function __construct(
    private readonly DataPackage $package,
  ) {}

  /**
   * Default location: outside the web root, next to vendor/.
   */
  public static function defaultFile(): string {
    return dirname(DRUPAL_ROOT) . '/data/sunset-package.jsonl.gz';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gnwt_sunset_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $file = self::defaultFile();
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Package file: %file (@state). Create it locally with <code>drush sunset:package</code> and upload it with the code.', [
        '%file' => $file,
        '@state' => is_readable($file) ? $this->t('found, @size MB', ['@size' => round(filesize($file) / 1048576, 1)]) : $this->t('missing'),
      ]) . '</p><p>' . $this->t('Rows currently stored: @count.', ['@count' => number_format($this->package->storedRows())]) . '</p>',
    ];
    $form['replace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace the current inventory (all mappings and review decisions are deleted first)'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#disabled' => !is_readable($file),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('replace') && $this->package->storedRows() > 0) {
      $form_state->setErrorByName('replace', $this->t('The inventory is not empty: tick "Replace" to import anyway.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $file = self::defaultFile();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Importing the data package'))
      ->setFinishCallback([self::class, 'finished'])
      ->addOperation([self::class, 'process'], [$file, (bool) $form_state->getValue('replace')]);
    batch_set($batch->toArray());
  }

  /**
   * Batch operation: imports the package slice by slice.
   */
  public static function process(string $file, bool $replace, array &$context): void {
    /** @var \Drupal\gnwt_sunset\Package\DataPackage $package */
    $package = \Drupal::service('gnwt_sunset.data_package');
    if (!isset($context['sandbox']['total'])) {
      if ($replace) {
        $package->clear();
      }
      $context['sandbox']['total'] = $package->count($file);
      $context['sandbox']['offset'] = 0;
    }
    $read = $package->importSlice($file, $context['sandbox']['offset'], self::SLICE);
    $context['sandbox']['offset'] += $read;
    $context['results']['lines'] = $context['sandbox']['offset'];
    $context['message'] = new TranslatableMarkup('@done of @total rows', [
      '@done' => number_format($context['sandbox']['offset']),
      '@total' => number_format($context['sandbox']['total']),
    ]);
    $context['finished'] = ($read === 0 || $context['sandbox']['total'] === 0)
      ? 1
      : min(1, $context['sandbox']['offset'] / $context['sandbox']['total']);
  }

  /**
   * Batch finish callback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    \Drupal::service('gnwt_sunset.data_package')->invalidate();
    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addStatus(new TranslatableMarkup('@count rows imported.', ['@count' => number_format($results['lines'] ?? 0)]));
    }
    else {
      $messenger->addError(new TranslatableMarkup('The import stopped before the end. Tick "Replace" and run it again.'));
    }
  }

}
