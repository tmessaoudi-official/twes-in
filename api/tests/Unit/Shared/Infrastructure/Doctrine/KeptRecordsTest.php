<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\KeptRecords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeptRecordsTest extends TestCase
{
    public function testTheKeptIdIsTheOneMergedIntoOrItsOwn(): void
    {
        self::assertSame('COALESCE(mp.merged_into_id, mp.id)', KeptRecords::id('mp'));
    }

    public function testTheJoinReachesTheRecordKeptThroughTheOneNamed(): void
    {
        self::assertSame(
            'LEFT JOIN vendor named ON named.id = e.vendor_id LEFT JOIN vendor v ON v.id = COALESCE(named.merged_into_id, named.id)',
            KeptRecords::join('vendor', 'e.vendor_id', 'v'),
        );
        self::assertSame(
            'JOIN customer cn ON cn.id = i.customer_id JOIN customer c ON c.id = COALESCE(cn.merged_into_id, cn.id)',
            KeptRecords::join('customer', 'i.customer_id', 'c', 'cn', required: true),
        );
    }

    public function testAColumnNamesTheRecordKeptOrAnyMergedIntoIt(): void
    {
        self::assertSame(
            'i.customer_id IN (SELECT stands.id FROM customer stands WHERE stands.id = :customer OR stands.merged_into_id = :customer)',
            KeptRecords::among('i.customer_id', 'customer', ':customer'),
        );
        self::assertSame(
            'l.product_id IN (SELECT stands.id FROM product stands WHERE stands.id = p.id OR stands.merged_into_id = p.id)',
            KeptRecords::among('l.product_id', 'product', 'p.id'),
        );
        self::assertSame(
            'l.product_id IN (SELECT stands.id FROM product stands WHERE stands.id IN (:products) OR stands.merged_into_id IN (:products))',
            KeptRecords::among('l.product_id', 'product', ':products', list: true),
        );
    }

    public function testARecordMergedAwayIsTheOnePointingElsewhere(): void
    {
        self::assertSame('p.merged_into_id IS NOT NULL', KeptRecords::mergedAway('p'));
    }

    /** @return iterable<string, array{\Closure(): string}> */
    public static function unsafe(): iterable
    {
        yield 'a table nothing merges' => [static fn (): string => KeptRecords::join('invoice', 'x.invoice_id', 'i')];
        yield 'an alias with a space' => [static fn (): string => KeptRecords::id('mp; DROP TABLE product')];
        yield 'a column with a quote' => [static fn (): string => KeptRecords::among("i.customer_id'", 'customer', ':customer')];
        yield 'a record kept neither bound nor a column' => [static fn (): string => KeptRecords::among('i.customer_id', 'customer', 'customer')];
        yield 'a bound parameter with more after it' => [static fn (): string => KeptRecords::among('i.customer_id', 'customer', ':customer OR true')];
        yield 'a list compared with a column' => [static fn (): string => KeptRecords::among('l.product_id', 'product', 'p.id', list: true)];
        yield 'the same alias twice' => [static fn (): string => KeptRecords::join('customer', 'i.customer_id', 'c', 'c')];
    }

    /** @param \Closure(): string $build */
    #[DataProvider('unsafe')]
    public function testWhatIsNotAnIdentifierIsRefused(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }
}
