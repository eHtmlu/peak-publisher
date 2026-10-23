<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\classify_ip_whitelist;
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
}
