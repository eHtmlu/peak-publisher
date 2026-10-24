<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\classify_ip_whitelist;
use function Pblsh\ip_in_cidr;
use function Pblsh\whitelist_requirements_error;

/**
 * The IP whitelist of the public API (includes/settings.php): what its entries are read as,
 * and what the server must have for them.
 */
final class IpWhitelistTest extends TestCase {

    public function test_entries_are_read_by_kind_and_the_rest_is_dropped(): void {
        $lists = classify_ip_whitelist([ ' 203.0.113.7 ', '2001:db8::1', '10.0.0.0/8', '2001:db8::/32', 'updates.example', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/x', '' ]);

        self::assertSame([ 'addresses' => [ '203.0.113.7' ], 'ranges' => [ [ '10.0.0.0', 8 ] ] ], $lists[4]);
        self::assertSame([ 'addresses' => [ '2001:db8::1' ], 'ranges' => [ [ '2001:db8::', 32 ] ] ], $lists[6]);
        self::assertSame([ 'updates.example' ], $lists['domains']);
    }

    public function test_domain_names_need_the_intl_extension(): void {
        self::assertNull(whitelist_requirements_error([ '203.0.113.7', '10.0.0.0/8' ]), 'addresses and ranges need nothing');

        $error = whitelist_requirements_error([ 'updates.example' ]);
        if (function_exists('idn_to_ascii')) {
            self::assertNull($error);
        } else {
            self::assertSame([ 'settings_intl_missing', 400, 'ip_whitelist' ], [ $error->get_error_code(), $error->get_error_data()['status'], $error->get_error_data()['field'] ]);
        }
    }

    public function test_an_address_is_in_a_cidr_range_of_its_own_family(): void {
        self::assertTrue(ip_in_cidr('10.20.30.40', '10.0.0.0', 8));
        self::assertTrue(ip_in_cidr('192.168.1.77', '192.168.1.0', 24));
        self::assertFalse(ip_in_cidr('192.168.2.77', '192.168.1.0', 24));
        self::assertTrue(ip_in_cidr('203.0.113.9', '203.0.113.8', 31), 'a prefix that ends inside a byte');
        self::assertFalse(ip_in_cidr('203.0.113.10', '203.0.113.8', 31));
        self::assertTrue(ip_in_cidr('203.0.113.9', '203.0.113.9', 32), '/32 is the address itself');
        self::assertFalse(ip_in_cidr('203.0.113.8', '203.0.113.9', 32));
        self::assertTrue(ip_in_cidr('8.8.8.8', '0.0.0.0', 0), '/0 is everything');

        self::assertTrue(ip_in_cidr('2001:db8:1:2::9', '2001:db8::', 32));
        self::assertFalse(ip_in_cidr('2001:db9::1', '2001:db8::', 32));
        self::assertTrue(ip_in_cidr('2001:db8:0:0:ffff::1', '2001:db8::', 64));
        self::assertFalse(ip_in_cidr('2001:db8:0:1::1', '2001:db8::', 64));
        self::assertTrue(ip_in_cidr('2001:db8::1', '2001:DB8:0:0:0:0:0:1', 128), 'the same address in another spelling');

        self::assertFalse(ip_in_cidr('192.168.1.1', '2001:db8::', 32), 'families never mix');
        self::assertFalse(ip_in_cidr('2001:db8::1', '192.168.1.0', 24));
        self::assertFalse(ip_in_cidr('not an ip', '192.168.1.0', 24));
        self::assertFalse(ip_in_cidr('192.168.1.1', '192.168.1.0', 33), 'a prefix beyond the family is no range');
    }
}
