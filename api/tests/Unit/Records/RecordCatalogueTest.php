<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordCatalogue;
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

        new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write')], [$order]), $this->declaring([], [$order])]);
    }

    public function testASubjectDeclaredTwiceStopsTheCatalogue(): void
    {
        $this->expectExceptionMessageMatches('/declares the subject vendor, which another module already declared/');

        new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write')], []), $this->declaring([new RecordSubject('vendor', 'vendor.write')], [])]);
    }

    public function testTheReferencesNamingASubjectLeaveOutThoseSaidOfEverySubject(): void
    {
        $order = RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order.');
        $journal = RecordReference::ignored('audit_log', 'entity_id', null, 'History.');

        $catalogue = new RecordCatalogue([$this->declaring([new RecordSubject('vendor', 'vendor.write')], [$order, $journal])]);

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
