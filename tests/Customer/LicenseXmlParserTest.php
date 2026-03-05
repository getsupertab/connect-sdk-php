<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Customer\LicenseXmlParser;

final class LicenseXmlParserTest extends TestCase
{
    private const SAMPLE_RSL_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl xmlns="https://rslstandard.org/rsl">
  <content url="http://127.0.0.1:7676/*" server="http://127.0.0.1:8787">
    <license type="application/vnd.readium.license.status.v1.0+json">
      <link rel="self" href="http://127.0.0.1:8787/license" />
    </license>
  </content>
  <content url="http://127.0.0.1:7676/article/*" server="http://127.0.0.1:8787">
    <license type="application/vnd.readium.license.status.v1.0+json">
      <link rel="self" href="http://127.0.0.1:8787/license" />
    </license>
  </content>
</rsl>
XML;

    private const SAMPLE_NON_NAMESPACED_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://example.com/*" server="http://token.example.com">
    <license type="test">
      <link rel="self" href="http://token.example.com/license" />
    </license>
  </content>
</rsl>
XML;

    public function test_parses_namespaced_rsl_xml_with_multiple_content_blocks(): void
    {
        $blocks = LicenseXmlParser::parseContentElements(self::SAMPLE_RSL_XML);

        $this->assertCount(2, $blocks);

        $this->assertSame('http://127.0.0.1:7676/*', $blocks[0]->urlPattern);
        $this->assertSame('http://127.0.0.1:8787', $blocks[0]->server);
        $this->assertStringContainsString('<license', $blocks[0]->licenseXml);
        $this->assertStringContainsString('</license>', $blocks[0]->licenseXml);

        $this->assertSame('http://127.0.0.1:7676/article/*', $blocks[1]->urlPattern);
        $this->assertSame('http://127.0.0.1:8787', $blocks[1]->server);
    }

    public function test_parses_non_namespaced_xml(): void
    {
        $blocks = LicenseXmlParser::parseContentElements(self::SAMPLE_NON_NAMESPACED_XML);

        $this->assertCount(1, $blocks);
        $this->assertSame('http://example.com/*', $blocks[0]->urlPattern);
        $this->assertSame('http://token.example.com', $blocks[0]->server);
    }

    public function test_skips_content_missing_license(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://example.com/*" server="http://token.example.com">
  </content>
</rsl>
XML;

        $blocks = LicenseXmlParser::parseContentElements($xml);

        $this->assertCount(0, $blocks);
    }

    public function test_skips_content_missing_url_attribute(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content server="http://token.example.com">
    <license type="test"><link rel="self" href="http://example.com/license" /></license>
  </content>
</rsl>
XML;

        $blocks = LicenseXmlParser::parseContentElements($xml);

        $this->assertCount(0, $blocks);
    }

    public function test_skips_content_missing_server_attribute(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://example.com/*">
    <license type="test"><link rel="self" href="http://example.com/license" /></license>
  </content>
</rsl>
XML;

        $blocks = LicenseXmlParser::parseContentElements($xml);

        $this->assertCount(0, $blocks);
    }

    public function test_returns_empty_array_for_no_content_elements(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <something>not a content element</something>
</rsl>
XML;

        $blocks = LicenseXmlParser::parseContentElements($xml);

        $this->assertCount(0, $blocks);
    }

    public function test_returns_empty_array_for_malformed_input(): void
    {
        $blocks = LicenseXmlParser::parseContentElements('not xml at all');

        $this->assertCount(0, $blocks);
    }

    public function test_preserves_full_license_xml_with_attributes_and_children(): void
    {
        $blocks = LicenseXmlParser::parseContentElements(self::SAMPLE_RSL_XML);

        $this->assertNotEmpty($blocks);
        $licenseXml = $blocks[0]->licenseXml;

        // Should contain the license element with its type attribute
        $this->assertStringContainsString('type=', $licenseXml);
        // Should contain the nested link element
        $this->assertStringContainsString('<link', $licenseXml);
        $this->assertStringContainsString('href=', $licenseXml);
    }
}
