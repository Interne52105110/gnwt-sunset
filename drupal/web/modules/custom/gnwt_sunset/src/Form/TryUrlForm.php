<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gnwt_sunset\Redirect\Resolver;
use Drupal\gnwt_sunset\UrlNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shows where a legacy URL would be redirected, without redirecting.
 *
 * The real redirect needs the legacy domain to point at the platform; this
 * form runs the same resolver so visitors can see the decision. It also
 * answers GET requests (/try?url=...), so results can be linked and shared.
 */
final class TryUrlForm extends FormBase {

  private const DEFAULT_URL = 'https://www.hss.gov.nt.ca/en/services/medical-travel';

  public function __construct(
    private readonly Resolver $resolver,
    private readonly UrlNormalizer $normalizer,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'gnwt_sunset_try_url';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $requested = (string) $this->getRequest()->query->get('url', '');
    $url = (string) ($form_state->getValue('url') ?? ($requested !== '' ? $requested : self::DEFAULT_URL));

    $form['#attributes']['class'][] = 'try-form';
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Legacy URL'),
      '#description' => $this->t('Any address on a legacy gov.nt.ca site. Results can be shared: the address of this page includes the URL you tried.'),
      '#default_value' => $url,
      '#required' => TRUE,
      '#maxlength' => 2048,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Where would it go?'),
    ];

    if ($form_state->isSubmitted() || $requested !== '') {
      // Form actions default to weight 100: show the result after the button.
      $form['result'] = $this->explain($url) + ['#weight' => 200];
    }
    $form['#cache']['contexts'][] = 'url.query_args:url';
    $form['#cache']['tags'][] = 'sunset_url_list';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Redirect to a shareable address that shows the result.
    $form_state->setRedirect('gnwt_sunset.try', [], ['query' => ['url' => $form_state->getValue('url')]]);
  }

  /**
   * Builds the result for one legacy URL.
   */
  private function explain(string $url): array {
    $parts = parse_url($url) ?: [];
    $resolution = $this->resolver->resolve($parts['host'] ?? '', $parts['path'] ?? '/');
    $build = [
      '#theme' => 'sunset_try_result',
      '#url' => $url,
    ];
    if ($resolution === NULL) {
      $build['#explanation'] = $this->t('This host is not one of the legacy gov.nt.ca sites, so the toolkit leaves it alone.');
      return $build;
    }

    $explanations = [
      'home' => $this->t('A legacy home page goes to the new home page, in the same language.'),
      'approved' => $this->t('An editor approved this mapping: the redirect is permanent.'),
      'suggested' => $this->t('Unreviewed high-confidence suggestion, served temporarily.'),
      'gone' => $this->t('An editor found no equivalent: the resident gets a search on the new site, built from the words of the old address.'),
      'unreviewed' => $this->t('A page is suggested but nobody has confirmed it yet. Until then the resident gets a temporary search on the new site, built from the words of the old address, rather than a 404.'),
      'unknown' => $this->t('This address is not in the inventory. The resident still avoids a 404: a temporary search on the new site.'),
    ];
    $build['#status'] = $resolution->status;
    $build['#destination'] = $resolution->url;
    $build['#explanation'] = $explanations[$resolution->reason] ?? $resolution->reason;

    $normalized = $this->normalizer->normalize($url);
    if ($normalized !== NULL && $resolution->reason !== 'approved') {
      $row = $this->database->select('sunset_url', 's')
        ->fields('s', ['target_url', 'confidence', 'method', 'review'])
        ->condition('url_hash', $normalized->hash)
        ->execute()
        ->fetchAssoc();
      if ($row && $row['target_url']) {
        $methods = [
          'slug_exact' => $this->t('same words in the address'),
          'slug_fuzzy' => $this->t('similar address'),
          'parent' => $this->t('sub-page merged into its parent'),
          'manual' => $this->t('set by an editor'),
        ];
        $build['#suggestion'] = [
          'url' => $row['target_url'],
          'method' => $methods[$row['method']] ?? $row['method'],
          'confidence' => number_format((float) $row['confidence'], 2),
          'review' => $row['review'] === 'pending' ? $this->t('to review') : $row['review'],
        ];
      }
      elseif ($row) {
        $build['#explanation'] .= ' ' . $this->t('The address carries no words to match (for example /fr/node/4474), so the home page is used.');
      }
    }
    return $build;
  }

}
