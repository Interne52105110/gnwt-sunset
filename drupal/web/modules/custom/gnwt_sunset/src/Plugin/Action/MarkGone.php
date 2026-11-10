<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\Entity\SunsetUrl;

/**
 * Records that a legacy URL has no equivalent on the new site.
 */
#[Action(
  id: 'gnwt_sunset_gone',
  label: new TranslatableMarkup('Mark as having no equivalent (search fallback)'),
  type: 'sunset_url'
)]
final class MarkGone extends ReviewActionBase {

  /**
   * {@inheritdoc}
   */
  protected function reviewState(): string {
    return SunsetUrl::REVIEW_GONE;
  }

}
