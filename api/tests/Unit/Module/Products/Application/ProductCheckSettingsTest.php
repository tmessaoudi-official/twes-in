<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\Module\Products\Application\Check\ProductCheckSettings;
use App\Module\Products\Domain\ValueObject\ProductCheckReason;
use App\Settings\Domain\ValueObject\SettingType;
use PHPUnit\Framework\TestCase;

/**
 * The reasons a company may leave out are written out for the label gate: they are exactly the reasons saying a field
 * is empty, in the order a screen lists them, and none is left out until the company chooses.
 */
final class ProductCheckSettingsTest extends TestCase
{
    public function testItOffersExactlyTheReasonsThatMayBeLeftOutAndLeavesNoneOutByDefault(): void
    {
        $definitions = iterator_to_array(new ProductCheckSettings()->settings(), false);

        self::assertCount(1, $definitions);
        [$leftOut] = $definitions;
        self::assertSame([ProductCheckSettings::LEFT_OUT, SettingType::Choices, []], [$leftOut->key, $leftOut->type, $leftOut->default]);
        $offered = array_map(static fn (ProductCheckReason $reason): string => $reason->value, array_values(array_filter(ProductCheckReason::cases(), static fn (ProductCheckReason $reason): bool => $reason->mayBeLeftOut())));
        self::assertSame($offered, $leftOut->choices);
        self::assertSame(['price_missing', 'vendor_missing', 'vat_missing', 'cost_missing', 'barcode_missing', 'category_missing'], $offered, 'what an import doubts is always said');
    }
}
