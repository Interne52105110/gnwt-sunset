<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public overview of the demo: the problem, the numbers, the method.
 */
final class OverviewController extends ControllerBase {

  /**
   * Official Indigenous languages of the NWT, as they appear in file names.
   */
  private const INDIGENOUS = [
    'inuktitut', 'inuinnaqtun', 'inuvialuktun', 'tlicho', 'chipewyan',
    'cree', 'gwichin', 'slavey',
  ];

  /**
   * Legacy URLs offered as examples to try.
   */
  private const EXAMPLES = [
    'cannabis/youth' => 'https://www.hss.gov.nt.ca/en/services/cannabis/youth',
    'medical-travel' => 'https://www.hss.gov.nt.ca/en/services/medical-travel',
    'a French PDF' => 'https://www.hss.gov.nt.ca/sites/hss/files/resources/tobacco-quit-plan-fr.pdf',
    '/fr/node/4474' => 'https://www.hss.gov.nt.ca/fr/node/4474',
  ];

  public function __construct(
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'config.factory')]
    ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'request_stack')]
    private readonly RequestStack $requestStack,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * Builds the overview page.
   *
   * When the overview is the front page, /sunset answers with a permanent
   * redirect to /, so that one page has one address.
   */
  public function page(): array|Response {
    $front = (string) $this->config('system.site')->get('page.front');
    $isFront = $front === '/sunset';
    if ($isFront && $this->requestStack->getCurrentRequest()?->getPathInfo() === '/sunset') {
      $response = new TrustedRedirectResponse(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), 301);
      $response->addCacheableDependency((new CacheableMetadata())
        ->addCacheTags(['config:system.site'])
        ->addCacheContexts(['url.path']));
      return $response;
    }

    $high = (float) $this->config('gnwt_sunset.settings')->get('high_threshold');

    $hosts = [];
    $totals = ['total' => 0, 'files' => 0, 'suggested' => 0, 'high' => 0, 'approved' => 0];
    $result = $this->database->query("SELECT host, COUNT(*) AS total,
        SUM(CASE WHEN kind = 'file' THEN 1 ELSE 0 END) AS files,
        SUM(CASE WHEN target_url IS NOT NULL THEN 1 ELSE 0 END) AS suggested,
        SUM(CASE WHEN target_url IS NOT NULL AND confidence >= :high THEN 1 ELSE 0 END) AS high,
        SUM(CASE WHEN review = 'approved' THEN 1 ELSE 0 END) AS approved
      FROM {sunset_url} GROUP BY host ORDER BY total DESC", [':high' => $high]);
    foreach ($result as $record) {
      $row = ['host' => (string) $record->host];
      foreach (array_keys($totals) as $key) {
        $row[$key] = (int) $record->{$key};
        $totals[$key] += $row[$key];
      }
      $row['pct'] = $row['total'] ? (int) round(100 * $row['suggested'] / $row['total']) : 0;
      $hosts[] = $row;
    }

    $build = [
      '#theme' => 'sunset_overview',
      '#stats' => [
        'targets' => (int) $this->database->query('SELECT COUNT(*) FROM {sunset_target}')->fetchField(),
        'legacy' => $totals['total'],
        'files' => $totals['files'],
        'suggested' => $totals['suggested'],
        'suggested_pct' => $totals['total'] ? (int) round(100 * $totals['suggested'] / $totals['total']) : 0,
        'high' => $totals['high'],
        'approved' => $totals['approved'],
      ] + $this->indigenousDocuments(),
      '#hosts' => $hosts,
      '#example' => $this->example(),
      '#examples' => array_map(
        static fn (string $label, string $url): array => ['label' => $label, 'url' => $url],
        array_keys(self::EXAMPLES),
        array_values(self::EXAMPLES),
      ),
      '#try_url' => Url::fromRoute('gnwt_sunset.try')->toString(),
      '#mappings_url' => Url::fromRoute('view.gnwt_sunset_review.public')->toString(),
      '#cache' => [
        'tags' => ['sunset_url_list', 'sunset_target_list', 'config:gnwt_sunset.settings', 'config:system.site'],
        'max-age' => 3600,
      ],
    ];
    $canonical = $isFront
      ? Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString()
      : Url::fromRoute('gnwt_sunset.overview', [], ['absolute' => TRUE])->toString();
    $build['#attached']['html_head_link'][] = [['rel' => 'canonical', 'href' => $canonical], TRUE];
    return $build;
  }

  /**
   * Counts documents whose file name names an Indigenous language.
   *
   * @return array{indigenous: int, indigenous_matched: int}
   *   Documents found, and those with a suggested page.
   */
  private function indigenousDocuments(): array {
    // Whole words only: "cree" must not match "screening".
    $query = $this->database->select('sunset_url', 's')->condition('kind', 'file');
    $or = $query->orConditionGroup();
    foreach (self::INDIGENOUS as $index => $language) {
      $or->where("CONCAT(' ', tokens, ' ') LIKE :word$index", [":word$index" => '% ' . $this->database->escapeLike($language) . ' %']);
    }
    $query->condition($or);
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression('SUM(CASE WHEN target_url IS NOT NULL THEN 1 ELSE 0 END)', 'matched');
    $row = $query->execute()->fetchAssoc() ?: [];
    return [
      'indigenous' => (int) ($row['total'] ?? 0),
      'indigenous_matched' => (int) ($row['matched'] ?? 0),
    ];
  }

  /**
   * A real sub-page mapped to the page it was merged into.
   *
   * @return array{old: string, new: string, method: string, confidence: string}|null
   *   The example, or NULL before any data is imported.
   */
  private function example(): ?array {
    $row = $this->database->select('sunset_url', 's')
      ->fields('s', ['url', 'target_url', 'method', 'confidence'])
      ->condition('path', '/en/services/cannabis/youth')
      ->isNotNull('target_url')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $methods = [
      'slug_exact' => $this->t('same words in the address'),
      'slug_fuzzy' => $this->t('similar address'),
      'parent' => $this->t('sub-page merged into its parent'),
    ];
    return [
      'old' => $row['url'],
      'new' => $row['target_url'],
      'method' => (string) ($methods[$row['method']] ?? $row['method']),
      'confidence' => number_format((float) $row['confidence'], 2),
    ];
  }

}
