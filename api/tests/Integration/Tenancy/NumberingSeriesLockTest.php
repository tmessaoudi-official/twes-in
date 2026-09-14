<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\NumberFormat;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\NumberingSeriesRepository;
use App\Tenancy\Domain\ResetPeriod;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Gapless numbering rests on one row lock: the transaction that takes a number holds its series until it commits,
 * so a second one waits instead of taking the same number. The test's own connection plays the request taking the
 * number; a second, plain connection plays the other request. The rows it locks on are committed by that second
 * connection, since the test bundle rolls the first one's back and nobody else would see them, and are removed
 * once the test has let go of them.
 */
final class NumberingSeriesLockTest extends KernelTestCase
{
    /** PostgreSQL's "lock not available", which a lock_timeout raises. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private static ?Connection $other = null;

    /** @var list<string> */
    private static array $companies = [];

    public function testASecondTransactionWaitsForTheSeriesTheFirstOneNumbersFrom(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $mine = $entityManager->getConnection();
        $url = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($url);
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $params['dbname'] = $mine->getDatabase() ?? self::fail('The test connection names no database.');
        $other = self::$other = DriverManager::getConnection($params);

        $now = new \DateTimeImmutable();
        $company = new Company('Lock '.bin2hex(random_bytes(4)), 'TN', 'TND', 'fr', 'Africa/Tunis', $now);
        $establishment = Establishment::create($company, '000', 'Siège', true, $now);
        $series = NumberingSeries::create($company, $establishment, 'delivery_note', new NumberFormat('BL-{SEQ}'), ResetPeriod::Never, true, $now);
        $committer = new EntityManager($other, $entityManager->getConfiguration());
        $committer->persist($company);
        $committer->persist($establishment);
        $committer->persist($series);
        $committer->flush();
        self::$companies[] = $company->getId()->toRfc4122();

        // The test bundle's transaction sits below the DBAL wrapper, which Doctrine's lock check does not see: the
        // request's own transaction is opened here, the way DoctrineTransactions opens it.
        $mine->beginTransaction();
        try {
            $locked = static::getContainer()->get(NumberingSeriesRepository::class)->lockedDefaultFor($establishment->getId(), 'delivery_note');
            self::assertNotNull($locked);
            self::assertTrue($locked->getId()->equals($series->getId()));

            $other->executeStatement("SET lock_timeout = '300ms'");
            try {
                $other->fetchOne('SELECT next_number FROM numbering_series WHERE id = ? FOR UPDATE', [$series->getId()->toRfc4122()]);
                self::fail('A second transaction locked the series the first one is numbering from.');
            } catch (DriverException $waited) {
                self::assertSame(self::LOCK_NOT_AVAILABLE, $waited->getSQLState());
            }
        } finally {
            $mine->rollBack();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$other) {
            foreach (self::$companies as $id) {
                self::$other->executeStatement('DELETE FROM company WHERE id = ?', [$id]);
            }
            self::$other->close();
            self::$other = null;
            self::$companies = [];
        }
        parent::tearDownAfterClass();
    }
}
