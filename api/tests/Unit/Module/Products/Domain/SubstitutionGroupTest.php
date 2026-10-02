<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use PHPUnit\Framework\TestCase;

/**
 * The substitution group a product belongs to (docs/SPEC.md § 7): a name the products themselves carry. It is trimmed,
 * a blank one is none, and it is one of the fields a revision reports as changed.
 */
final class SubstitutionGroupTest extends TestCase
{
    public function testAGroupIsTrimmedABlankOneIsNoneAndATooLongOneIsRefused(): void
    {
        self::assertSame('Clavier AZERTY', $this->details('  Clavier AZERTY ')->substitutionGroup);
        self::assertNull($this->details('   ')->substitutionGroup);
        self::assertNull($this->details(null)->substitutionGroup);
        self::assertSame(str_repeat('é', ProductDetails::GROUP_MAX), $this->details(str_repeat('é', ProductDetails::GROUP_MAX))->substitutionGroup);
        try {
            $this->details(str_repeat('é', ProductDetails::GROUP_MAX + 1));
            self::fail('a group above the limit was kept');
        } catch (InvalidProduct $refused) {
            self::assertSame('substitutionGroup', $refused->field);
        }
    }

    public function testChangingTheGroupIsAChangedFieldAndTheCostSwapKeepsIt(): void
    {
        $left = $this->details('A');
        self::assertSame(['substitutionGroup'], $left->differencesFrom($this->details('B')));
        self::assertSame([], $left->differencesFrom($this->details('A')));
        self::assertSame('A', $left->withCostPrice('1.0000')->substitutionGroup);
    }

    private function details(?string $group): ProductDetails
    {
        return new ProductDetails('Clavier', null, ProductKind::Goods, '10', null, $group);
    }
}
