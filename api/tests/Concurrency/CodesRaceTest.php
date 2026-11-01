<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Module\Customers\Application\CustomerNumberTaken;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\RaceStep;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\CreateCustomer;
use App\Tests\Concurrency\Support\Steps\CreateVendor;
use App\Tests\Concurrency\Support\Steps\TakeProductReference;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The codes a record takes that are not a document's number. A product saved with no reference takes the next one from
 * the company's counter, made on its first use: eight at once on a company that has none yet take eight references,
 * each once. A customer number is typed: two people typing the same at once make one customer, and the other is
 * refused, by the use case's own check or by the unique index under it, which the API answers busy and the screen
 * sends again, to be refused in words then. Never two records of one number.
 */
final class CodesRaceTest extends SaleRaceTestCase
{
    private const int RACERS = 8;

    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '1';
    }

    public function testEightProductsSavedAtOnceWithNoReferenceTakeEightReferences(): void
    {
        $outcomes = Race::run(TakeProductReference::class, self::RACERS, ['company' => $this->company->getId()->toRfc4122()]);

        $references = [];
        foreach ($outcomes as $outcome) {
            self::assertSame(Outcome::OK, $outcome->kind, $outcome->describe());
            self::assertIsString($outcome->value);
            $references[] = $outcome->value;
        }
        self::assertCount(self::RACERS, array_unique($references), 'each reference once: '.implode(', ', $references));
        self::assertSame(self::RACERS + 1, Rows::int($this->em()->getConnection()->fetchOne(
            'SELECT next_number FROM product_reference_sequence WHERE company_id = ?',
            [$this->company->getId()->toRfc4122()],
        )), 'the counter moved once per product, none lost');
    }

    /** @return iterable<string, array{class-string<RaceStep>, class-string<\RuntimeException>, string, string}> */
    public static function typedNumbers(): iterable
    {
        yield 'a customer number' => [CreateCustomer::class, CustomerNumberTaken::class, 'customer', 'CLI-0100'];
        yield 'a vendor number' => [CreateVendor::class, VendorNumberTaken::class, 'vendor', 'FRN-0100'];
    }

    /**
     * @param class-string<RaceStep>          $step
     * @param class-string<\RuntimeException> $taken
     */
    #[DataProvider('typedNumbers')]
    public function testTwoRecordsTypedWithOneNumberAtOnceMakeOne(string $step, string $taken, string $table, string $number): void
    {
        $this->customer(); // the preset's regimes, which a customer names, synced as at start
        $outcomes = Race::run($step, 2, ['company' => $this->company->getId()->toRfc4122(), 'number' => $number]);

        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes)));
        $refusals = '/'.preg_quote(self::shortName($taken), '/').'|'.preg_quote(self::shortName(UniqueConstraintViolationException::class), '/').'/';
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertMatchesRegularExpression($refusals, $outcome->message, 'refused for the number, nothing else');
            }
        }
        self::assertSame(1, Rows::int($this->em()->getConnection()->fetchOne(
            \sprintf('SELECT count(*) FROM %s WHERE company_id = ? AND number = ?', $table),
            [$this->company->getId()->toRfc4122(), $number],
        )), 'never two records of one number');
    }

    /** @param class-string $class */
    private static function shortName(string $class): string
    {
        return new \ReflectionClass($class)->getShortName();
    }
}
