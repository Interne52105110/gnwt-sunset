<?php

declare(strict_types=1);

namespace Drupal\gnwt_sunset\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\gnwt_sunset\UrlNormalizer;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the lookup key of a legacy URL.
 *
 * Spreadsheet URLs differ from stored ones (case, trailing slash, "www."),
 * so mappings are matched on the normalized key, not on the raw URL.
 *
 * @code
 * id:
 *   - plugin: gnwt_sunset_url_hash
 *     source: legacy_url
 *   - plugin: entity_lookup
 *     entity_type: sunset_url
 *     value_key: url_hash
 * @endcode
 */
#[MigrateProcess('gnwt_sunset_url_hash')]
final class UrlHash extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly UrlNormalizer $normalizer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('gnwt_sunset.url_normalizer'));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): ?string {
    return $this->normalizer->normalize((string) $value)?->hash;
  }

}
