<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\ClientIpNormalizer;

final class ClientIpNormalizerTest extends TestCase
{
    public function test_maps_ipv4_to_ipv6_mapped_form(): void
    {
        $this->assertSame('::ffff:203.0.113.1', ClientIpNormalizer::normalize('203.0.113.1'));
    }

    public function test_returns_valid_ipv6_unchanged(): void
    {
        $this->assertSame('2001:db8::1', ClientIpNormalizer::normalize('2001:db8::1'));
    }

    public function test_returns_loopback_ipv6_unchanged(): void
    {
        $this->assertSame('::1', ClientIpNormalizer::normalize('::1'));
    }

    public function test_trims_surrounding_whitespace(): void
    {
        $this->assertSame('::ffff:203.0.113.1', ClientIpNormalizer::normalize('  203.0.113.1  '));
    }

    public function test_null_becomes_unspecified(): void
    {
        $this->assertSame('::', ClientIpNormalizer::normalize(null));
    }

    public function test_empty_string_becomes_unspecified(): void
    {
        $this->assertSame('::', ClientIpNormalizer::normalize(''));
    }

    public function test_whitespace_only_becomes_unspecified(): void
    {
        $this->assertSame('::', ClientIpNormalizer::normalize('   '));
    }

    public function test_garbage_becomes_unspecified(): void
    {
        $this->assertSame('::', ClientIpNormalizer::normalize('not-an-ip'));
    }

    public function test_out_of_range_ipv4_becomes_unspecified(): void
    {
        $this->assertSame('::', ClientIpNormalizer::normalize('999.999.999.999'));
    }
}
