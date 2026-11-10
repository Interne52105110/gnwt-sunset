<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Hook;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for the sunset toolkit.
 */
final class SunsetHooks {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: 'extension.list.theme')]
    private readonly ThemeExtensionList $themes,
    #[Autowire(service: 'path.matcher')]
    private readonly PathMatcherInterface $pathMatcher,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * Public pages get a description and a share card (Open Graph), so a link
   * posted on LinkedIn shows a title, a summary and an image.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $pages = [
      'gnwt_sunset.overview' => [
        'title' => 'Ten old websites. Thousands of links. Not one dead end.',
        'description' => 'A Drupal 11 toolkit that finds the GNWT.ca page for every old gov.nt.ca URL, lets editors approve it, and answers the old addresses with the right redirect. Independent demo.',
      ],
      'gnwt_sunset.try' => [
        'title' => 'Try a legacy URL · Sunset Toolkit',
        'description' => 'Paste an old gov.nt.ca address and see where a resident would land, and why.',
      ],
      'view.gnwt_sunset_review.public' => [
        'title' => 'Suggested mappings · Sunset Toolkit',
        'description' => 'Every legacy URL of the health site and the GNWT.ca page suggested for it, with method and confidence.',
      ],
    ];
    $page = $pages[$this->routeMatch->getRouteName()] ?? NULL;
    if ($page === NULL) {
      return;
    }

    // Same address as the canonical link: the front page is shared as "/".
    $route = $this->pathMatcher->isFrontPage() ? '<front>' : '<current>';
    $url = Url::fromRoute($route, [], ['absolute' => TRUE])->toString();
    $tags = [
      ['name', 'description', $page['description']],
      ['property', 'og:type', 'website'],
      ['property', 'og:site_name', 'Sunset Toolkit'],
      ['property', 'og:title', $page['title']],
      ['property', 'og:description', $page['description']],
      ['property', 'og:url', $url],
      ['name', 'twitter:card', 'summary_large_image'],
    ];
    if ($this->themes->exists('sunset_theme')) {
      $image = Url::fromUri('base:' . $this->themes->getPath('sunset_theme') . '/images/social-card.png', ['absolute' => TRUE])->toString();
      $tags[] = ['property', 'og:image', $image];
      $tags[] = ['property', 'og:image:width', '1200'];
      $tags[] = ['property', 'og:image:height', '630'];
      $tags[] = ['property', 'og:image:alt', 'Sunset Toolkit: ten old websites, thousands of links, not one dead end.'];
    }
    foreach ($tags as [$attribute, $name, $content]) {
      $attachments['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [$attribute => $name, 'content' => $content],
        ],
        'gnwt_sunset_' . str_replace(':', '_', $name),
      ];
    }
    $attachments['#cache']['contexts'][] = 'route';
    $attachments['#cache']['contexts'][] = 'url.path.is_front';
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'sunset_overview' => [
        'variables' => [
          'stats' => [],
          'hosts' => [],
          'example' => NULL,
          'examples' => [],
          'try_url' => NULL,
          'mappings_url' => NULL,
        ],
      ],
      'sunset_try_result' => [
        'variables' => [
          'url' => NULL,
          'status' => NULL,
          'destination' => NULL,
          'explanation' => NULL,
          'suggestion' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.gnwt_sunset') {
      return NULL;
    }
    return '<p>' . $this->t('The sunset toolkit helps retire legacy gov.nt.ca sites without breaking links.') . '</p>'
      . '<ol>'
      . '<li>' . $this->t('<code>drush sunset:targets</code> reads the GNWT.ca sitemap.') . '</li>'
      . '<li>' . $this->t('<code>drush sunset:legacy</code> lists the URLs of a legacy host from the Internet Archive, without loading the old servers.') . '</li>'
      . '<li>' . $this->t('<code>drush sunset:match</code> suggests a GNWT.ca page for each legacy URL.') . '</li>'
      . '<li>' . $this->t('Editors approve, correct or reject each suggestion under Content » Legacy URLs.') . '</li>'
      . '<li>' . $this->t('When a legacy domain points at this site, approved URLs get a 301; the rest get a temporary 302 to a search.') . '</li>'
      . '</ol>';
  }

}
