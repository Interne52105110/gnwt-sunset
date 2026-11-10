<?php

declare(strict_types=1);

namespace Drupal\Tests\gnwt_sunset\Kernel;

use Drupal\gnwt_sunset\Entity\SunsetUrl;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the whole pipeline: sitemap, legacy URLs, matching, review, redirect.
 */
#[Group('gnwt_sunset')]
final class SunsetPipelineTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'views', 'gnwt_sunset'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('sunset_url');
    $this->installEntitySchema('sunset_target');
    $this->installConfig(['gnwt_sunset']);

    $stats = $this->container->get('gnwt_sunset.sitemap_importer')
      ->import(__DIR__ . '/../../fixtures/sitemap');
    $this->assertSame(['seen' => 6, 'created' => 5, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1], $stats);
  }

  /**
   * Legacy URLs get the right suggestion, or none.
   */
  public function testMatching(): void {
    $travel = $this->legacy('https://www.hss.gov.nt.ca/en/services/medical-travel/');
    $manual = $this->legacy('https://www.hss.gov.nt.ca/sites/hss/files/resources/tuberculosis-manual_0.pdf');
    $french = $this->legacy('https://www.hss.gov.nt.ca/fr/services/deplacements-pour-raisons-medicales');
    $orphan = $this->legacy('https://www.hss.gov.nt.ca/en/services/bed-bugs');
    $child = $this->legacy('https://www.hss.gov.nt.ca/en/services/medical-travel/appealing-medical-travel-decisions');

    $stats = $this->container->get('gnwt_sunset.matcher')->run();
    $this->assertSame(5, $stats['processed']);
    $this->assertSame(1, $stats['parent']);

    $storage = $this->container->get('entity_type.manager')->getStorage('sunset_url');
    $storage->resetCache();

    $travel = $storage->load($travel->id());
    $this->assertSame('https://gnwt.ca/health-and-wellness/medical-travel', $travel->get('target_url')->value);
    $this->assertSame('slug_exact', $travel->get('method')->value);

    // The document goes to the resource record, not to the topic page.
    $manual = $storage->load($manual->id());
    $this->assertSame('https://gnwt.ca/resources/tuberculosis-manual', $manual->get('target_url')->value);

    // French legacy pages are matched against gtno.ca only.
    $french = $storage->load($french->id());
    $this->assertSame('https://gtno.ca/sante-et-mieux-etre/deplacements-pour-raisons-medicales', $french->get('target_url')->value);

    $orphan = $storage->load($orphan->id());
    $this->assertTrue($orphan->get('target_url')->isEmpty());
    $this->assertSame('none', $orphan->get('method')->value);

    // A sub-page merged into its parent gets the parent, below "high".
    $child = $storage->load($child->id());
    $this->assertSame('https://gnwt.ca/health-and-wellness/medical-travel', $child->get('target_url')->value);
    $this->assertSame('parent', $child->get('method')->value);
    $this->assertLessThan(0.85, (float) $child->get('confidence')->value);
  }

  /**
   * Only approved mappings are permanent; the rest falls back to search.
   */
  public function testResolver(): void {
    $travel = $this->legacy('https://www.hss.gov.nt.ca/en/services/medical-travel');
    $this->container->get('gnwt_sunset.matcher')->run();
    $resolver = $this->container->get('gnwt_sunset.resolver');

    $pending = $resolver->resolve('www.hss.gov.nt.ca', '/en/services/medical-travel');
    $this->assertSame(302, $pending->status);
    $this->assertSame('unreviewed', $pending->reason);
    $this->assertSame('https://gnwt.ca/search?keywords=medical%20travel', $pending->url);

    $travel = $this->container->get('entity_type.manager')->getStorage('sunset_url')->load($travel->id());
    $travel->set('review', SunsetUrl::REVIEW_APPROVED)->save();

    $approved = $resolver->resolve('HSS.gov.nt.ca', '/EN/services/Medical-Travel/');
    $this->assertSame(301, $approved->status);
    $this->assertSame('https://gnwt.ca/health-and-wellness/medical-travel', $approved->url);

    $this->assertSame('https://gtno.ca/', $resolver->resolve('www.hss.gov.nt.ca', '/fr')->url);
    $this->assertSame(
      'https://gnwt.ca/search?keywords=cannabis%20youth',
      $resolver->resolve('www.hss.gov.nt.ca', '/en/services/cannabis/youth')->url,
    );
    $this->assertSame('https://gtno.ca/', $resolver->resolve('www.hss.gov.nt.ca', '/fr/node/4474')->url);
    $this->assertSame(
      'https://gtno.ca/search?keywords=tobacco%20quit%20plan',
      $resolver->resolve('www.hss.gov.nt.ca', '/sites/hss/files/resources/tobacco-quit-plan-fr_0.pdf')->url,
    );
    $this->assertSame('unknown', $resolver->resolve('www.hss.gov.nt.ca', '/en/never-seen')->reason);
    $this->assertNull($resolver->resolve('gnwt.ca', '/en/services/medical-travel'));
  }

  /**
   * A request on a legacy host is redirected before routing.
   */
  public function testLegacyHostRequest(): void {
    $travel = $this->legacy('https://www.hss.gov.nt.ca/en/services/medical-travel');
    $this->container->get('gnwt_sunset.matcher')->run();
    $travel = $this->container->get('entity_type.manager')->getStorage('sunset_url')->load($travel->id());
    $travel->set('review', SunsetUrl::REVIEW_APPROVED)->save();

    $response = $this->container->get('http_kernel')->handle(Request::create('https://www.hss.gov.nt.ca/en/services/medical-travel'));
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('https://gnwt.ca/health-and-wellness/medical-travel', $response->headers->get('Location'));
    $this->assertSame('approved', $response->headers->get('X-GNWT-Sunset'));
  }

  /**
   * A data package survives a round trip, imported in small slices.
   */
  public function testDataPackageRoundTrip(): void {
    $this->legacy('https://www.hss.gov.nt.ca/en/services/medical-travel');
    $this->legacy('https://www.hss.gov.nt.ca/en/services/bed-bugs');
    $this->container->get('gnwt_sunset.matcher')->run();

    $package = $this->container->get('gnwt_sunset.data_package');
    // gzopen() cannot write to the virtual file system of kernel tests.
    $base = tempnam(sys_get_temp_dir(), 'sunset');
    unlink($base);
    $file = $base . '.jsonl.gz';
    $this->assertSame(['sunset_target' => 5, 'sunset_url' => 2], $package->export($file));
    $this->assertSame(7, $package->count($file));

    $package->clear();
    $this->assertSame(0, $package->storedRows());
    for ($offset = 0; ($read = $package->importSlice($file, $offset, 3)) > 0; $offset += $read) {
      // Three lines per slice, like small batch requests.
    }
    $this->assertSame(7, $package->storedRows());

    // Imported rows are real entities again.
    $entities = $this->container->get('entity_type.manager')->getStorage('sunset_url')
      ->loadByProperties(['path' => '/en/services/medical-travel']);
    $this->assertCount(1, $entities);
    $entity = reset($entities);
    $this->assertSame('https://gnwt.ca/health-and-wellness/medical-travel', $entity->get('target_url')->value);
    $this->assertNotEmpty($entity->uuid());
    unlink($file);
  }

  /**
   * Creates a legacy URL record.
   */
  private function legacy(string $url): SunsetUrl {
    $entity = $this->container->get('entity_type.manager')->getStorage('sunset_url')->create([
      'url' => $url,
      'source' => 'test',
    ]);
    $entity->save();
    return $entity;
  }

}
