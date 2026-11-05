<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use PHPUnit\Framework\TestCase;

/**
 * A second move condition narrows the rows a first one moves, as a credit note draft is kept out of the drafts a customer
 * merge moves; declared any other way it is refused when the declaration is read.
 */
final class RecordReferenceTest extends TestCase
{
    public function testASecondConditionNarrowsTheFirstAndOutlivesTheDeclarationsAfterIt(): void
    {
        $reference = RecordReference::uses('invoice', 'customer_id', 'customer', RecordMerge::Moves, 'why')
            ->movingOnlyWhere('status', ['draft'])
            ->andOnlyWhere('document_type', ['invoice'])
            ->transactingAt('issued_at');

        self::assertSame(['status', ['draft'], 'document_type', ['invoice'], 'issued_at'], [$reference->movesWhereColumn, $reference->movesWhereValues, $reference->movesAlsoWhereColumn, $reference->movesAlsoWhereValues, $reference->transactingAt]);
    }

    public function testASecondConditionWithoutAFirstIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('invoice.customer_id: a second condition narrows the rows a first one moves, once, by at least one value.'));

        RecordReference::uses('invoice', 'customer_id', 'customer', RecordMerge::Moves, 'why')->andOnlyWhere('document_type', ['invoice']);
    }

    public function testASecondConditionIsGivenOnceAndNamesAValue(): void
    {
        $first = RecordReference::uses('invoice', 'customer_id', 'customer', RecordMerge::Moves, 'why')->movingOnlyWhere('status', ['draft']);
        $refused = 0;
        foreach ([static fn () => $first->andOnlyWhere('document_type', []), static fn () => $first->andOnlyWhere('document_type', ['invoice'])->andOnlyWhere('kind', ['x'])] as $declare) {
            try {
                $declare();
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
    }

    public function testASecondConditionNamesAColumnTheEngineMayWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::uses('invoice', 'customer_id', 'customer', RecordMerge::Moves, 'why')
            ->movingOnlyWhere('status', ['draft'])
            ->andOnlyWhere('type; DROP TABLE invoice', ['invoice']);
    }
}
