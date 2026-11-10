<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\Entity\SunsetUrl;

/**
 * Sends mappings back to the review queue.
 */
#[Action(
  id: 'gnwt_sunset_reset',
  label: new TranslatableMarkup('Send back to review'),
  type: 'sunset_url'
)]
final class ResetReview extends ReviewActionBase {

  /**
   * {@inheritdoc}
   */
  protected function reviewState(): string {
    return SunsetUrl::REVIEW_PENDING;
  }

}
