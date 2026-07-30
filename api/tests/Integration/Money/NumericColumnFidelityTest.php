<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Money;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * The COLUMN, against real PostgreSQL — the claim `CLAUDE.md` and `phpunit.xml` were both making unbacked.
 *
 * Both files stated, present tense, that the integration suite proves money "column fidelity". A
 * certification round found it did not: all 39 integration tests were tenancy, and there was no `NUMERIC`
 * column anywhere in `api/tests/`. Meanwhile `Money::MAX_INTEGER_DIGITS = 15` and
 * `Rate::MAX_INTEGER_DIGITS = 15` are each justified in their own docblocks by a claim about what
 * `NUMERIC(19,4)` and `NUMERIC(27,12)` can hold. Those bounds are the reason a wrong amount cannot reach a
 * document, and nothing checked that the database agrees with them.
 *
 * `#[CoversNothing]`: the subject is the agreement between two constants and a column type, not a class.
 */
#[CoversNothing]
final class NumericColumnFidelityTest extends TestCase
{
    private \PDO $connection;

    protected function setUp(): void
    {
        $this->connection = self::connect();

        // A TEMPORARY table, so the runtime role needs no DDL on the schema and nothing survives the
        // connection — this suite shares its database with the tenancy tests, whose policed-table counts a
        // stray table would break.
        $this->connection->exec(
            'CREATE TEMPORARY TABLE money_fidelity_probe (
                id           integer PRIMARY KEY,
                amount       NUMERIC(19,4),
                profit_rate  NUMERIC(27,12)
            )',
        );
    }

    /**
     * Every amount a `Money` can hold survives a round trip through `NUMERIC(19,4)` EXACTLY.
     *
     * Exactly, not approximately: the value read back must be numerically equal to the value written, so a
     * silent truncation or a float conversion in the driver would fail here rather than at a customer.
     */
    #[DataProvider('amountsThatMustSurviveTheColumn')]
    public function testAnAmountSurvivesTheNumericColumnExactly(string $amount, string $currency): void
    {
        // Constructed through Money first, so the test cannot pass for a value the domain would reject.
        $money = Money::of($amount, Currency::of($currency));

        $insert = $this->connection->prepare(
            'INSERT INTO money_fidelity_probe (id, amount) VALUES (1, ?)',
        );
        $insert->execute([$money->amount()]);

        $read = $this->connection->query('SELECT amount FROM money_fidelity_probe WHERE id = 1');
        self::assertNotFalse($read);
        $stored = $read->fetchColumn();
        self::assertIsString($stored, 'pdo_pgsql must return NUMERIC as a string, never a float.');

        // Compared as numbers, because NUMERIC(19,4) pads to its scale: 19.99 comes back as 19.9900.
        self::assertSame(
            0,
            bccomp($money->amount(), $stored, 12),
            \sprintf('wrote %s, read %s', $money->amount(), $stored),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function amountsThatMustSurviveTheColumn(): iterable
    {
        // The largest amount Money permits — 15 integer digits, which is exactly what NUMERIC(19,4) holds.
        yield 'the largest representable amount' => ['999999999999999.999', 'TND'];
        yield 'the largest at 4 decimals' => ['999999999999999.9999', 'CLF'];
        yield "Tunisia's stamp duty, 100 millimes" => ['0.100', 'TND'];
        yield 'one millime' => ['0.001', 'TND'];
        yield 'an ordinary two-decimal price' => ['19.99', 'EUR'];
        yield 'a zero-decimal currency' => ['12345', 'JPY'];
        yield 'negative, because a credit note is negative' => ['-1234.567', 'TND'];
        yield 'zero' => ['0.000', 'TND'];
    }

    /**
     * SIXTEEN integer digits are REFUSED by the column, not silently truncated.
     *
     * This is the assumption `Money::MAX_INTEGER_DIGITS = 15` rests on. If PostgreSQL rounded instead of
     * raising, the domain bound would be a convenience rather than a guarantee, and a value that slipped past
     * it would land in the database wrong rather than not at all.
     */
    public function testSixteenIntegerDigitsAreRefusedByTheColumnRatherThanTruncated(): void
    {
        $insert = $this->connection->prepare(
            'INSERT INTO money_fidelity_probe (id, amount) VALUES (2, ?)',
        );

        try {
            $insert->execute(['1000000000000000.0000']);
            self::fail('NUMERIC(19,4) must refuse 16 integer digits.');
        } catch (\PDOException $exception) {
            // 22003 numeric_value_out_of_range. Asserted on the SQLSTATE rather than the message, which is
            // localised.
            self::assertSame('22003', $exception->getCode(), $exception->getMessage());
        }
    }

    /**
     * A `Rate` at full precision survives `NUMERIC(27,12)` exactly, including the twelfth decimal.
     *
     * Twelve decimals is the whole point of `Rate::FRACTION_SCALE`: at six, a cost of 10 000.000 with a price
     * of 10 000.001 rounded to a rate of zero and a later cost change deleted the margin. If the column cannot
     * hold the twelfth decimal, that fix is undone at the persistence boundary.
     */
    #[DataProvider('ratesThatMustSurviveTheColumn')]
    public function testARateSurvivesItsColumnExactly(string $fraction): void
    {
        $rate = Rate::fromFraction($fraction);

        $insert = $this->connection->prepare(
            'INSERT INTO money_fidelity_probe (id, profit_rate) VALUES (3, ?)',
        );
        $insert->execute([$rate->fraction()]);

        $read = $this->connection->query('SELECT profit_rate FROM money_fidelity_probe WHERE id = 3');
        self::assertNotFalse($read);
        $stored = $read->fetchColumn();
        self::assertIsString($stored);

        self::assertSame(0, bccomp($rate->fraction(), $stored, 14), \sprintf(
            'wrote %s, read %s',
            $rate->fraction(),
            $stored,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function ratesThatMustSurviveTheColumn(): iterable
    {
        yield 'thirty percent' => ['0.300000000000'];
        yield 'the twelfth decimal, which six decimals lost' => ['0.000000100000'];
        yield 'the smallest representable rate' => ['0.000000000001'];
        yield 'a negative rate — selling below cost is a real decision' => ['-0.150000000000'];
        yield 'the largest storable rate' => ['999999999999999'];
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');

        // FAIL, NEVER SKIP — see the class docblock and `CLAUDE.md` § "Quality gate", which promises exactly
        // this and which the original code contradicted.
        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::fail('TWES_TEST_DSN, TWES_TEST_DB_USER and TWES_TEST_DB_PASSWORD must be set.');
        }

        try {
            return new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $exception) {
            self::fail(DatabaseRequirement::unreachable($exception));
        }
    }
}
