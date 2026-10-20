<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\MergedAway;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordLink;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;
use App\Records\Application\RecordUse;
use PHPUnit\Framework\TestCase;

final class RecordCatalogueTest extends TestCase
{
    public function testAReferenceNamingNoDeclaredSubjectStopsTheCatalogue(): void
    {
        $this->expectExceptionMessage('purchase_order.vendor_id names vendor, which no module declares a subject.');

        new RecordCatalogue([$this->declaring([], [RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order.')])]);
    }

    public function testAColumnSaidTwiceOfOneSubjectStopsTheCatalogue(): void
    {
        $order = RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order.');

        $this->expectExceptionMessage('purchase_order.vendor_id>vendor is declared twice.');

        new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write', 'vendors', ['number', 'name'])], [$order]), $this->declaring([], [$order])]);
    }

    public function testASubjectDeclaredTwiceStopsTheCatalogue(): void
    {
        $this->expectExceptionMessageMatches('/declares the subject vendor, which another module already declared/');

        new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write', 'vendors', ['number', 'name'])], []), $this->declaring([new RecordSubject('vendor', 'vendor.write', 'vendors', ['number', 'name'])], [])]);
    }

    public function testTheReferencesNamingASubjectLeaveOutThoseSaidOfEverySubject(): void
    {
        $order = RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order.');
        $journal = RecordReference::ignored('audit_log', 'entity_id', null, 'History.');

        $catalogue = new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write', 'vendors', ['number', 'name'])], [$order, $journal])]);

        self::assertSame([$order], $catalogue->naming('vendor'));
    }

    public function testOnlyAColumnThatNeverNamesASubjectLeavesItUnsaid(): void
    {
        self::assertSame(RecordUse::Ignored, RecordReference::ignored('setting', 'level_id', 'vendor', 'No vendor level.')->onDelete);
        self::assertNull(RecordReference::ignored('audit_log', 'entity_id', null, 'History.')->subject);
    }

    public function testANameThatIsNotAPlainIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::uses('purchase_order; DROP TABLE vendor', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order.');
    }

    public function testOnlyAReferenceThatMovesMovesSomeOfItsRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::uses('stock_movement', 'vendor_id', 'vendor', RecordMerge::Stays, 'A movement.')->movingOnlyWhere('status', ['draft']);
    }

    public function testAColumnSaidOfEverySubjectNamesNoneOnSomeRowsOnly(): void
    {
        $owned = RecordReference::owned('setting', 'level_id', 'product', RecordMerge::MovesOrDrops, 'A product setting.', RecordLink::CompanyPath)->where('level', 'product');
        self::assertSame(['level', 'product'], [$owned->whereColumn, $owned->whereValue]);

        $this->expectException(\InvalidArgumentException::class);

        RecordReference::ignored('audit_log', 'entity_id', null, 'History.')->where('entity_type', 'product');
    }

    public function testAScopeColumnThatIsNotAPlainIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::owned('setting', 'level_id', 'product', RecordMerge::Stays, 'A product setting.')->where('level = level OR 1', 'product');
    }

    /** A merge drops the row the record kept already has a twin of: what makes a twin must be said, or the unique index refuses. */
    public function testAReferenceDroppingTwinsSaysWhatMakesOne(): void
    {
        $this->expectExceptionMessage('product_vendor.product_id drops its twins in a merge and says no column that makes one.');

        new RecordCatalogue([$this->declaring([new RecordSubject('product', 'product.write', 'products', ['reference'])], [RecordReference::owned('product_vendor', 'product_id', 'product', RecordMerge::MovesOrDrops, 'A vendor link.')])]);
    }

    public function testOnlyAReferenceDroppingTwinsSaysWhatMakesOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::uses('product', 'category_id', 'product_category', RecordMerge::Moves, 'Filed.')->droppingTwinsBy('reference');
    }

    public function testATwinIsNamedByPlainIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordReference::owned('product_vendor', 'product_id', 'product', RecordMerge::MovesOrDrops, 'A vendor link.')->droppingTwinsBy('vendor_id = vendor_id OR 1');
    }

    /** A record a merge deletes cannot leave a row naming it: such a subject's merged-away record stays, an alias. */
    public function testASubjectAMergeRemovesHasNoRowThatStaysWithIt(): void
    {
        $this->expectExceptionMessage('product_category is removed by a merge, and invoice_line.category_id keeps naming the record merged away.');

        new RecordCatalogue([$this->declaring(
            [new RecordSubject('product_category', 'product.write', 'products', ['name'], MergedAway::Removed)],
            [RecordReference::uses('invoice_line', 'category_id', 'product_category', RecordMerge::Stays, 'Printed.')],
        )]);
    }

    public function testASubjectIsAliasedByAMergeUnlessItSaysOtherwise(): void
    {
        self::assertSame(MergedAway::Aliased, new RecordSubject('vendor', 'vendor.write', 'vendors', ['name'])->mergedAway);
    }

    /**
     * @param list<RecordSubject>   $subjects
     * @param list<RecordReference> $references
     */
    private function declaring(array $subjects, array $references): DeclaresRecordReferences
    {
        return new readonly class($subjects, $references) implements DeclaresRecordReferences {
            /**
             * @param list<RecordSubject>   $subjects
             * @param list<RecordReference> $references
             */
            public function __construct(private array $subjects, private array $references)
            {
            }

            public function subjects(): array
            {
                return $this->subjects;
            }

            public function references(): array
            {
                return $this->references;
            }
        };
    }
}
