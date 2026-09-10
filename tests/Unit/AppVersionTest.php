<?php

namespace Tests\Unit;

use App\Support\AppVersion;
use PHPUnit\Framework\TestCase;

/**
 * A plain PHPUnit test with no application booted — the same discipline
 * SqlDumperTest keeps, and for a related reason: this class decides whether a
 * handset is allowed to talk to the server, and it should be provable without
 * a framework behind it.
 */
class AppVersionTest extends TestCase
{
    public function test_it_orders_versions_by_each_segment(): void
    {
        $this->assertSame(-1, AppVersion::compare('1.2.3', '1.2.4'));
        $this->assertSame(-1, AppVersion::compare('1.2.3', '1.3.0'));
        $this->assertSame(-1, AppVersion::compare('1.9.9', '2.0.0'));
        $this->assertSame(1, AppVersion::compare('2.0.0', '1.9.9'));
        $this->assertSame(0, AppVersion::compare('1.2.3', '1.2.3'));
    }

    public function test_it_compares_numerically_not_as_strings(): void
    {
        // The whole reason this is not a string comparison: '10' sorts before
        // '9' as text, so a string compare would refuse the newest build in
        // the fleet as too old.
        $this->assertSame(1, AppVersion::compare('1.10.0', '1.9.0'));
        $this->assertSame(1, AppVersion::compare('10.0.0', '9.0.0'));
    }

    public function test_missing_segments_count_as_zero(): void
    {
        $this->assertSame(0, AppVersion::compare('1.2', '1.2.0'));
        $this->assertSame(0, AppVersion::compare('2', '2.0.0'));
        $this->assertSame(-1, AppVersion::compare('1.2', '1.2.1'));
    }

    public function test_the_build_number_is_not_part_of_the_version(): void
    {
        // Flutter writes `1.4.0+37`. The stores order releases by that suffix,
        // but two builds of one version speak the same API.
        $this->assertSame(0, AppVersion::compare('1.4.0+37', '1.4.0'));
        $this->assertSame(0, AppVersion::compare('1.4.0+37', '1.4.0+2'));
        $this->assertSame(-1, AppVersion::compare('1.4.0+99', '1.4.1+1'));
    }

    public function test_anything_unreadable_is_a_refusal_to_judge(): void
    {
        // Null, not -1. A caller that read this as "older" would lock a whole
        // company out of the app it clocks in with over a typo.
        $this->assertNull(AppVersion::compare(null, '1.0.0'));
        $this->assertNull(AppVersion::compare('', '1.0.0'));
        $this->assertNull(AppVersion::compare('v1.0.0', '1.0.0'));
        $this->assertNull(AppVersion::compare('1.0.0-beta', '1.0.0'));
        $this->assertNull(AppVersion::compare('nightly', '1.0.0'));
        $this->assertNull(AppVersion::compare('1.0.0', 'not a version'));
    }

    public function test_is_older_than_says_no_when_it_cannot_tell(): void
    {
        $this->assertTrue(AppVersion::isOlderThan('1.0.0', '1.1.0'));
        $this->assertFalse(AppVersion::isOlderThan('1.1.0', '1.1.0'));
        $this->assertFalse(AppVersion::isOlderThan('1.2.0', '1.1.0'));

        // No floor set, or a floor nobody can parse: nothing is refused.
        $this->assertFalse(AppVersion::isOlderThan('1.0.0', null));
        $this->assertFalse(AppVersion::isOlderThan('1.0.0', ''));
        $this->assertFalse(AppVersion::isOlderThan(null, '9.9.9'));
        $this->assertFalse(AppVersion::isOlderThan('rubbish', '9.9.9'));
    }
}
