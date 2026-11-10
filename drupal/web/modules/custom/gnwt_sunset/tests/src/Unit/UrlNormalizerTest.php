<?php

declare(strict_types=1);

namespace Drupal\Tests\gnwt_sunset\Unit;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\gnwt_sunset\UrlNormalizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests URL normalization.
 */
#[CoversClass(UrlNormalizer::class)]
#[Group('gnwt_sunset')]
final class UrlNormalizerTest extends UnitTestCase {

  /**
   * The normalizer under test.
   */
  private UrlNormalizer $normalizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->normalizer = new UrlNormalizer(new PhpTransliteration());
  }

  /**
   * A legacy page is reduced to host, path, language, section and words.
   */
  public function testPage(): void {
    $url = $this->normalizer->normalize('https://WWW.HSS.gov.nt.ca/en/Services/Medical-Travel/?utm_source=x#top');
    $this->assertNotNull($url);
    $this->assertSame('hss.gov.nt.ca', $url->host);
    $this->assertSame('/en/services/medical-travel', $url->path);
    $this->assertSame('https://www.hss.gov.nt.ca/en/services/medical-travel', $url->url);
    $this->assertSame('en', $url->lang);
    $this->assertSame('page', $url->kind);
    $this->assertSame('services', $url->section);
    $this->assertSame(['medical', 'travel'], $url->tokens);
  }

  /**
   * Documents keep their file name as slug, without numeric suffixes.
   */
  public function testDocument(): void {
    $url = $this->normalizer->normalize('https://www.hss.gov.nt.ca/sites/hss/files/resources/TB-Manual_0.pdf');
    $this->assertSame('file', $url->kind);
    $this->assertSame('files', $url->section);
    $this->assertSame('tb-manual_0', $url->slug);
    $this->assertSame(['tb', 'manual'], $url->tokens);
    $this->assertSame('und', $url->lang);

    $french = $this->normalizer->normalize('https://www.hss.gov.nt.ca/sites/hss/files/resources/tobacco-quit-plan-fr_0.pdf');
    $this->assertSame('fr', $french->lang);
    $this->assertSame(['tobacco', 'quit', 'plan'], $french->tokens);
  }

  /**
   * Department prefixes before the language are understood.
   */
  public function testDepartmentPrefix(): void {
    $url = $this->normalizer->normalize('https://www.gov.nt.ca/ecc/en/services/wildfire-operations');
    $this->assertSame('en', $url->lang);
    $this->assertSame('services', $url->section);
    $this->assertSame('gov.nt.ca', $url->host);
  }

  /**
   * Variants of one page share one lookup key.
   */
  public function testVariantsShareOneHash(): void {
    $a = $this->normalizer->normalize('http://hss.gov.nt.ca/en/foo');
    $b = $this->normalizer->normalize('https://www.hss.gov.nt.ca:443/EN//foo/?page=2');
    $this->assertSame($a->hash, $b->hash);
  }

  /**
   * System paths and assets are not inventoried.
   */
  #[DataProvider('excludedProvider')]
  public function testExcluded(string $url): void {
    $this->assertNull($this->normalizer->normalize($url));
  }

  /**
   * URLs that must be excluded.
   */
  public static function excludedProvider(): array {
    return [
      'login' => ['https://www.hss.gov.nt.ca/en/user/login'],
      'search' => ['https://www.hss.gov.nt.ca/en/search?keys=flu'],
      'theme asset' => ['https://www.hss.gov.nt.ca/sites/all/themes/gnwt_base_theme/logo.png'],
      'image style' => ['https://www.hss.gov.nt.ca/sites/hss/files/styles/large/public/photo.jpg'],
      'feed' => ['https://www.hss.gov.nt.ca/en/newsroom/feed'],
      'node edit' => ['https://www.hss.gov.nt.ca/en/node/12/edit'],
      'no host' => ['/en/relative'],
    ];
  }

  /**
   * Tokenization: transliteration, stop words, numbers and plurals.
   */
  public function testTokenize(): void {
    $this->assertSame(['sante', 'mieux', 'etre'], $this->normalizer->tokenize('santé-et-mieux-être'));
    $this->assertSame(['annual', 'report', '2024', '2025'], $this->normalizer->tokenize('Annual_Report_2024-2025'));
    $this->assertSame(['daily', 'update', 'september', '16', '2021'], $this->normalizer->tokenize('daily-update-september-16-2021'));
    $this->assertSame(['contaminant', 'fact', 'sheet', 'caribou'], $this->normalizer->tokenize('contaminants-fact-sheets-caribou'));
    $this->assertSame(['access', 'virus', 'analysis'], $this->normalizer->tokenize('access-virus-analysis'));
  }

  /**
   * Node ids carry no words; sub-pages remember their parent.
   */
  public function testNodeAndParent(): void {
    $this->assertSame([], $this->normalizer->normalize('https://www.hss.gov.nt.ca/fr/node/4474')->tokens);
    $child = $this->normalizer->normalize('https://www.hss.gov.nt.ca/en/services/medical-travel/appealing-medical-travel-decisions');
    $this->assertSame(['appealing', 'medical', 'travel', 'decision'], $child->tokens);
    $this->assertSame(['medical', 'travel'], $child->parentTokens);
    $this->assertSame([], $this->normalizer->normalize('https://www.hss.gov.nt.ca/en/services/medical-travel')->parentTokens);
  }

}
