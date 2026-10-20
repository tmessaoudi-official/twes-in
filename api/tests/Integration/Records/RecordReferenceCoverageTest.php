<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;
use App\Records\Infrastructure\RecordReferenceCoverage;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A record is « used » while a row names it, so every column that may name a subject's records must be declared, with
 * what a delete and a merge do about it: one forgotten, and a delete passes over a vendor a purchase order still names,
 * or a merge leaves that order naming the vendor that went.
 */
final class RecordReferenceCoverageTest extends KernelTestCase
{
    public function testEveryColumnNamingASubjectsRecordsIsDeclared(): void
    {
        self::assertSame([], RecordReferenceCoverage::unaccounted($this->connection(), $this->catalogue()));
    }

    public function testTheVendorIsASubject(): void
    {
        self::assertArrayHasKey('vendor', $this->catalogue()->subjects());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function forgotten(): iterable
    {
        yield 'a foreign key named after the subject' => ['expense', 'vendor_id', 'expense.vendor_id names vendor records and is not declared'];
        yield 'a foreign key named otherwise' => ['product_barcode', 'supplier_id', 'product_barcode.supplier_id names vendor records and is not declared'];
        yield 'a column with no foreign key' => ['purchase_order', 'vendor_id', 'purchase_order.vendor_id names vendor records and is not declared'];
        yield 'a column naming rows of any table' => ['attachment', 'entity_id', 'attachment.entity_id may name vendor records and is not declared'];
        yield 'a text column naming rows of any table' => ['setting', 'level_id', 'setting.level_id may name vendor records and is not declared'];
    }

    #[DataProvider('forgotten')]
    public function testAColumnLeftUndeclaredIsNamed(string $table, string $column, string $said): void
    {
        $without = $this->catalogueWithout(static fn (RecordReference $reference): bool => $table === $reference->table && $column === $reference->column);

        self::assertContains($said, RecordReferenceCoverage::unaccounted($this->connection(), $without));
    }

    public function testADeclaredColumnThatNoLongerExistsIsNamed(): void
    {
        $gone = new readonly class implements DeclaresRecordReferences {
            public function subjects(): array
            {
                return [];
            }

            public function references(): array
            {
                return [RecordReference::uses('expense', 'old_vendor_id', 'vendor', RecordMerge::Stays, 'A column since dropped.')];
            }
        };

        self::assertContains('expense.old_vendor_id is declared and no longer exists', RecordReferenceCoverage::unaccounted($this->connection(), new RecordCatalogue([...$this->declarations(), $gone])));
    }

    public function testASubjectWithNoTableIsNamed(): void
    {
        $gone = new readonly class implements DeclaresRecordReferences {
            public function subjects(): array
            {
                return [new RecordSubject('old_supplier', 'vendor.write', null, ['name'])];
            }

            public function references(): array
            {
                return [];
            }
        };

        self::assertContains('the subject old_supplier has no table', RecordReferenceCoverage::unaccounted($this->connection(), new RecordCatalogue([...$this->declarations(), $gone])));
    }

    /** @param callable(RecordReference): bool $drop */
    private function catalogueWithout(callable $drop): RecordCatalogue
    {
        $subjects = [];
        $references = [];
        foreach ($this->declarations() as $declaration) {
            $subjects = [...$subjects, ...$declaration->subjects()];
            foreach ($declaration->references() as $reference) {
                if (!$drop($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return new RecordCatalogue([new readonly class($subjects, $references) implements DeclaresRecordReferences {
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
        }]);
    }

    /** @return list<DeclaresRecordReferences> */
    private function declarations(): array
    {
        return $this->catalogue()->declarations();
    }

    private function catalogue(): RecordCatalogue
    {
        return static::getContainer()->get(RecordCatalogue::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
