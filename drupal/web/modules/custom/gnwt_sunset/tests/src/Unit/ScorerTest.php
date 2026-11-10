<?php

declare(strict_types=1);

namespace Drupal\Tests\gnwt_sunset\Unit;

use Drupal\gnwt_sunset\Import\WaybackImporter;
use Drupal\gnwt_sunset\Http\PoliteClient;
use Drupal\gnwt_sunset\Matching\Scorer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pure helpers: scoring, robots.txt and CDX parsing.
 */
#[Group('gnwt_sunset')]
final class ScorerTest extends UnitTestCase {

  /**
   * Identical sets score 1, disjoint sets 0, rare words weigh more.
   */
  public function testWeightedJaccard(): void {
    $idf = ['tuberculosis' => 5.0, 'program' => 0.5, 'manual' => 2.0];
    $this->assertSame(1.0, Scorer::weightedJaccard(['tuberculosis', 'manual'], ['manual', 'tuberculosis'], $idf, 6.0));
    $this->assertSame(0.0, Scorer::weightedJaccard(['program'], ['manual'], $idf, 6.0));
    $rare = Scorer::weightedJaccard(['tuberculosis', 'program'], ['tuberculosis'], $idf, 6.0);
    $common = Scorer::weightedJaccard(['tuberculosis', 'program'], ['program'], $idf, 6.0);
    $this->assertGreaterThan($common, $rare);
    $this->assertSame(0.0, Scorer::weightedJaccard([], ['program'], $idf, 6.0));
  }

  /**
   * Exact keys ignore word order and duplicates.
   */
  public function testKey(): void {
    $this->assertSame(Scorer::key(['travel', 'medical']), Scorer::key(['medical', 'travel', 'medical']));
  }

  /**
   * Documents lean towards resource records, pages away from them.
   */
  public function testSectionBonus(): void {
    $this->assertGreaterThan(0, Scorer::sectionBonus('file', 'files', 'resources'));
    $this->assertLessThan(0, Scorer::sectionBonus('page', 'services', 'ressources'));
    $this->assertGreaterThan(0, Scorer::sectionBonus('page', 'newsroom', 'nouvelles'));
    $this->assertSame(0.0, Scorer::sectionBonus('page', 'services', 'health-and-wellness'));
  }

  /**
   * Only the "*" group of robots.txt applies; wildcards are ignored.
   */
  public function testParseRobots(): void {
    $robots = "User-agent: Googlebot\nCrawl-delay: 1\nDisallow: /private/\n\n"
      . "User-agent: Bingbot\nUser-agent: *\nCrawl-delay: 10\n# comment\nDisallow: /search/\nDisallow: /*?page=\nDisallow:\n";
    $this->assertSame(['delay' => 10.0, 'disallow' => ['/search/']], PoliteClient::parseRobots($robots));
    $this->assertSame(['delay' => 0.0, 'disallow' => []], PoliteClient::parseRobots(''));
  }

  /**
   * The CDX resume key is separated from the rows.
   */
  public function testSplitResumeKey(): void {
    $data = [['original'], ['https://a/1'], [], ['resume-key']];
    $this->assertSame([[['original'], ['https://a/1']], 'resume-key'], WaybackImporter::splitResumeKey($data));
    $this->assertSame([[['original']], NULL], WaybackImporter::splitResumeKey([['original']]));
  }

}
