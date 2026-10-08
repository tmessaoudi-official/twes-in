<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Module\Invoices\Application\LateFeeSettings;
use App\Settings\Domain\SettingDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The late fee's tiers as written in their setting: one per reminder stage, an amount or a share of what is still due,
 * none where nothing is written. Nothing is charged until the company writes an amount: the law is not sourced.
 */
final class LateFeeSettingsTest extends TestCase
{
    /** @return iterable<string, array{string, int, string, int, string|null}> tiers, stage, amount due, scale, fee */
    public static function fees(): iterable
    {
        yield 'an amount at the stage' => ['5; 10; 20', 2, '300', 3, '10.000'];
        yield 'a decimal comma' => ['2,5', 1, '300', 3, '2.500'];
        yield 'a share of what is due' => ['5; 2 %', 2, '1234.567', 3, '24.691'];
        yield 'a share with a comma, in euros' => ['1,5%', 1, '99.99', 2, '1.50'];
        yield 'an amount in a currency of fewer decimals is rounded' => ['7.125', 1, '10', 2, '7.13'];
        yield 'an empty tier charges nothing' => ['; 10', 1, '300', 3, null];
        yield 'a zero charges nothing' => ['0; 10', 1, '300', 3, null];
        yield 'a stage beyond the tiers charges nothing' => ['5', 2, '300', 3, null];
        yield 'nothing written, nothing charged' => ['', 1, '300', 3, null];
        yield 'not tiers at all' => ['five', 1, '300', 3, null];
        yield 'six tiers are too many' => ['1; 2; 3; 4; 5; 6', 1, '300', 3, null];
    }

    #[DataProvider('fees')]
    public function testTheFeeIsTheStagesTier(string $tiers, int $stage, string $amountDue, int $scale, ?string $fee): void
    {
        self::assertSame($fee, LateFeeSettings::feeFor($tiers, $stage, $amountDue, $scale));
    }

    public function testOffByDefaultAndWithNoAmountUntilTheCompanyWritesOne(): void
    {
        $defaults = [];
        foreach (new LateFeeSettings()->settings() as $definition) {
            self::assertInstanceOf(SettingDefinition::class, $definition);
            $defaults[$definition->key] = $definition->default;
        }

        self::assertSame([LateFeeSettings::ENABLED => false, LateFeeSettings::TIERS => ''], $defaults);
    }
}
