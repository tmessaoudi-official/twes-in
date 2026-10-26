<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Purchases\Application;

use App\Module\Purchases\Application\Receivers\PurchaseReceiptSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Domain\ValueObject\ReceiverRule;
use PHPUnit\Framework\TestCase;

final class PurchaseReceiptSettingsTest extends TestCase
{
    public function testTheCompanyAloneSaysWhetherAReceiptNamesWhoReceivedOptionalByDefault(): void
    {
        $definitions = [...new PurchaseReceiptSettings()->settings()];

        self::assertCount(1, $definitions);
        self::assertSame(PurchaseReceiptSettings::RECEIVER, $definitions[0]->key);
        self::assertSame(array_map(static fn (ReceiverRule $rule): string => $rule->value, ReceiverRule::cases()), $definitions[0]->choices, 'the label gate reads them from the source, so they are written out and this keeps them equal');
        self::assertSame(ReceiverRule::Optional->value, $definitions[0]->default);
        self::assertSame([SettingChain::Parties, [SettingLevel::Company], 'purchases'], [$definitions[0]->chain, $definitions[0]->overridableAt, $definitions[0]->module]);
    }
}
