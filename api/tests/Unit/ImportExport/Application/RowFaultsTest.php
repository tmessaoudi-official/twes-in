<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowRejected;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Every fault of a row, gathered while its cells are read (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 9). */
#[CoversClass(RowFaults::class)]
#[CoversClass(RowRejected::class)]
final class RowFaultsTest extends TestCase
{
    public function testACellReadWithoutFaultAnswersItsValue(): void
    {
        $faults = new RowFaults();

        self::assertSame('12.5', $faults->read(static fn (): string => '12.5', 'instead'));
        $faults->stopIfAny();
    }

    public function testEveryRefusedCellIsKeptInOrderAndTheRowStopsWithThemAll(): void
    {
        $faults = new RowFaults();

        self::assertSame('instead', $faults->read(static fn (): never => throw new RowRejected('email', 'E-mail refused.', 'invalid_email'), 'instead'));
        $faults->read(static fn (): never => throw RowRejected::together([new RowRejected('phone', 'Phone refused.', 'invalid_phone'), new RowRejected('website', 'Website refused.', 'invalid_website')]));
        self::assertSame(7, $faults->read(static fn (): int => 7));

        try {
            $faults->stopIfAny();
            self::fail('a row holding faults went on');
        } catch (RowRejected $stopped) {
            self::assertSame(
                [['email', 'invalid_email'], ['phone', 'invalid_phone'], ['website', 'invalid_website']],
                array_map(static fn (RowRejected $one): array => [$one->column, $one->reason], $stopped->all()),
            );
            self::assertSame('E-mail refused.', $stopped->getMessage());
        }
    }

    public function testASingleRefusalIsAllItHolds(): void
    {
        $alone = new RowRejected('name', 'A name is needed.', 'value_required');

        self::assertSame([$alone], $alone->all());
    }
}
