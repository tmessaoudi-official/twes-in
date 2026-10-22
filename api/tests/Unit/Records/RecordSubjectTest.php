<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Records\Application\RecordSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordSubjectTest extends TestCase
{
    public function testARecordIsNamedThroughTwoTablesWhenItHoldsNoWordOfItsOwn(): void
    {
        $subject = new RecordSubject('recurring_invoice', 'invoice.write', 'recurring', [], labelTable: 'invoice', labelKey: 'model_invoice_id', labelTableColumns: ['number'], labelNextTable: 'customer', labelNextKey: 'customer_id', labelNextColumns: ['name']);

        self::assertSame(['customer', 'customer_id', ['name']], [$subject->labelNextTable, $subject->labelNextKey, $subject->labelNextColumns]);
    }

    /** @return iterable<string, array{callable(): RecordSubject}> */
    public static function badLabels(): iterable
    {
        yield 'named by nothing' => [static fn (): RecordSubject => new RecordSubject('recurring_invoice', 'invoice.write', null, [])];
        yield 'a further step without a first' => [static fn (): RecordSubject => new RecordSubject('recurring_invoice', 'invoice.write', null, ['id'], labelNextTable: 'customer', labelNextKey: 'customer_id', labelNextColumns: ['name'])];
        yield 'a further step without its columns' => [static fn (): RecordSubject => new RecordSubject('recurring_invoice', 'invoice.write', null, [], labelTable: 'invoice', labelKey: 'model_invoice_id', labelTableColumns: ['number'], labelNextTable: 'customer', labelNextKey: 'customer_id')];
        yield 'a further step that is not a name' => [static fn (): RecordSubject => new RecordSubject('recurring_invoice', 'invoice.write', null, [], labelTable: 'invoice', labelKey: 'model_invoice_id', labelTableColumns: ['number'], labelNextTable: 'customer; drop', labelNextKey: 'customer_id', labelNextColumns: ['name'])];
    }

    /** @param callable(): RecordSubject $declare */
    #[DataProvider('badLabels')]
    public function testALabelThatCannotBeReadIsRefusedAtDeclaration(callable $declare): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $declare();
    }
}
