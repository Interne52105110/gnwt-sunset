<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\gnwt_sunset\Entity\SunsetUrl;

/**
 * Sets the review state of legacy URLs from the bulk form.
 */
abstract class ReviewActionBase extends ActionBase {

  /**
   * The review state this action sets.
   */
  abstract protected function reviewState(): string;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof SunsetUrl && $this->applies($entity)) {
      $entity->set('review', $this->reviewState());
      $entity->save();
    }
  }

  /**
   * Whether the action may change this mapping.
   */
  protected function applies(SunsetUrl $entity): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    return $object->access('update', $account, $return_as_object);
  }

}
