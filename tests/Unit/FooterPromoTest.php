<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\footer_promo_index;
use function Pblsh\footer_promos;

/**
 * The footer's promotion of the day (includes/functions.php): two notes taking turns by UTC day,
 * the same for every reader on a given day.
 */
final class FooterPromoTest extends TestCase {

    public function test_the_day_since_the_epoch_picks_the_note_even_or_odd(): void {
        self::assertSame(0, footer_promo_index(0));
        self::assertSame(0, footer_promo_index(DAY_IN_SECONDS - 1), 'the last second of the first day');
        self::assertSame(1, footer_promo_index(DAY_IN_SECONDS), 'midnight UTC turns the page');
        self::assertSame(0, footer_promo_index(2 * DAY_IN_SECONDS));
        self::assertSame(0, footer_promo_index(gmmktime(12, 0, 0, 10, 20, 2026)), '2026-10-20 is day 20746, even');
    }

    public function test_two_notes_with_an_address_and_a_text_each(): void {
        $promos = footer_promos();

        self::assertCount(2, $promos);
        foreach ($promos as $promo) {
            self::assertMatchesRegularExpression('#^https://#', $promo['url']);
            self::assertNotSame('', $promo['text']);
        }
        self::assertStringContainsString('presscharts.com', $promos[0]['text'], 'even days');
        self::assertStringContainsString('Pro', $promos[1]['text'], 'odd days');
    }
}
