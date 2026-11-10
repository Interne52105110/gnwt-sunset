<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gnwt_sunset\Entity\SunsetUrl;

/**
 * Approves the suggested page; URLs without a suggestion are left as is.
 */
#[Action(
  id: 'gnwt_sunset_approve',
  label: new TranslatableMarkup('Approve the suggested page (301)'),
  type: 'sunset_url'
)]
final class ApproveMapping extends ReviewActionBase {

  /**
   * {@inheritdoc}
   */
  protected function reviewState(): string {
    return SunsetUrl::REVIEW_APPROVED;
  }

  /**
   * {@inheritdoc}
   */
  protected function applies(SunsetUrl $entity): bool {
    return !$entity->get('target_url')->isEmpty();
  }

}
