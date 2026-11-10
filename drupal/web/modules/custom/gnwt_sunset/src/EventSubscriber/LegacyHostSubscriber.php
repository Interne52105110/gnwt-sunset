<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\gnwt_sunset\Redirect\Resolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers requests that reach this site through a legacy host name.
 *
 * Once a legacy domain points at the new platform, every request for it is
 * redirected before routing: approved mappings with a 301, everything else
 * with a temporary 302 to a search, so no resident lands on a 404.
 */
final class LegacyHostSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Resolver $resolver,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before routing (32) and the maintenance mode check (30).
    return [KernelEvents::REQUEST => ['onRequest', 300]];
  }

  /**
   * Redirects legacy requests.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $resolution = $this->resolver->resolve($request->getHost(), $request->getPathInfo());
    if ($resolution === NULL) {
      return;
    }

    $response = new TrustedRedirectResponse($resolution->url, $resolution->status);
    $response->headers->set('X-GNWT-Sunset', $resolution->reason);
    $metadata = (new CacheableMetadata())
      ->addCacheTags(['sunset_url_list'])
      ->addCacheableDependency($this->configFactory->get('gnwt_sunset.settings'));
    $response->addCacheableDependency($metadata);
    $event->setResponse($response);
  }

}
