<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset;

use Drupal\views\EntityViewsData;

/**
 * Views integration of legacy URLs, with a bulk form for reviewers.
 */
final class SunsetUrlViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();
    // Base list fields get a text filter by default: offer their options.
    foreach (['review', 'method', 'kind'] as $field) {
      $data['sunset_url'][$field]['filter']['id'] = 'list_field';
      $data['sunset_url'][$field]['filter']['field_name'] = $field;
    }
    $data['sunset_url']['sunset_url_bulk_form'] = [
      'title' => $this->t('Legacy URL operations bulk form'),
      'help' => $this->t('Lets reviewers approve or reject several mappings at once.'),
      'field' => [
        'id' => 'bulk_form',
      ],
    ];
    return $data;
  }

}
