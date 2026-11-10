<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\GeneratedLink;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\gnwt_sunset\Entity\SunsetUrl;

/**
 * Lets a reviewer confirm, correct or reject the page suggested for a URL.
 */
final class SunsetUrlForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\gnwt_sunset\Entity\SunsetUrl $entity */
    $entity = $this->entity;

    if (!$entity->isNew()) {
      $form['url']['#disabled'] = TRUE;
      $items = [
        $this->t('Legacy page: @link', ['@link' => $this->externalLink((string) $entity->get('url')->value)]),
      ];
      if ($entity->get('target_url')->value) {
        $items[] = $this->t('Suggested page: @link', ['@link' => $this->externalLink((string) $entity->get('target_url')->value)]);
      }
      $items[] = $this->t('Match: @method, confidence @confidence', [
        '@method' => $entity->get('method')->value ?? $this->t('not matched yet'),
        '@confidence' => number_format((float) $entity->get('confidence')->value, 2),
      ]);
      if (!$entity->get('first_seen')->isEmpty()) {
        $items[] = $this->t('Archived between @first and @last', [
          '@first' => date('Y-m-d', (int) $entity->get('first_seen')->value),
          '@last' => date('Y-m-d', (int) $entity->get('last_seen')->value),
        ]);
      }
      if (!$entity->get('checked')->isEmpty()) {
        $items[] = $this->t('Last check: legacy URL answered @legacy, suggested page answered @target', [
          '@legacy' => $entity->get('live_status')->value ?? '-',
          '@target' => $entity->get('target_status')->value ?? '-',
        ]);
      }
      $form['context'] = [
        '#type' => 'details',
        '#title' => $this->t('Context'),
        '#open' => TRUE,
        '#weight' => -20,
        'facts' => ['#theme' => 'item_list', '#items' => $items],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($entity->get('review')->value === SunsetUrl::REVIEW_APPROVED && $entity->get('target_url')->isEmpty()) {
      $form_state->setErrorByName('target_url', $this->t('An approved mapping needs a GNWT.ca page. Choose "No equivalent" if there is none.'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\gnwt_sunset\Entity\SunsetUrl $entity */
    $entity = $this->entity;
    if (!$entity->isNew()) {
      $stored = $this->entityTypeManager->getStorage('sunset_url')->loadUnchanged($entity->id());
      if ($stored->get('target_url')->value !== $entity->get('target_url')->value) {
        $entity->set('method', 'manual');
        $entity->set('confidence', 1.0);
      }
    }
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Mapping saved for %url.', ['%url' => $entity->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('view.gnwt_sunset_review.page_1'));
    return $status;
  }

  /**
   * Builds an external link that opens in a new tab.
   */
  private function externalLink(string $url): GeneratedLink {
    return Link::fromTextAndUrl($url, Url::fromUri($url, ['attributes' => ['target' => '_blank', 'rel' => 'noopener']]))->toString();
  }

}
