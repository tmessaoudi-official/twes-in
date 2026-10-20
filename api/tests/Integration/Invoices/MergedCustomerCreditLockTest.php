<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Invoices\Domain\Repository\CustomerCreditRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The credit of a customer merged into another is held by the customer kept, so spending it from an invoice of either
 * takes the one lock: the row of the customer kept. Locking the row of the customer merged away would let an
 * application on each side both fit the same balance.
 *
 * A second connection cannot watch the lock here, as the test's own transaction keeps its rows from every other
 * session; PostgreSQL marks a locked row by writing the locker into its `xmax`, which the session holding it reads.
 * Pointing the customer merged away at the one kept already took a key-share lock on the row kept, so what is read is
 * whether the `xmax` moved: the lock taken to spend the balance adds itself there.
 */
final class MergedCustomerCreditLockTest extends KernelTestCase
{
    public function testSpendingFromTheCustomerMergedAwayLocksTheRowOfTheCustomerKept(): void
    {
        self::bootKernel();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $em->persist($company);
        $em->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $kept = Customer::create($company, 'CLI-1', new CustomerProfile(CustomerKind::Company, 'Grossiste'), null, $regime, [], new \DateTimeImmutable());
        $merged = Customer::create($company, 'CLI-2', new CustomerProfile(CustomerKind::Company, 'Grossiste Sfax'), null, $regime, [], new \DateTimeImmutable());
        $em->persist($kept);
        $em->persist($merged);
        $em->flush();
        $connection = $em->getConnection();
        $connection->executeStatement('UPDATE customer SET merged_into_id = ?, is_active = false WHERE id = ?', [$kept->getId()->toRfc4122(), $merged->getId()->toRfc4122()]);
        $em->clear();
        $credits = static::getContainer()->get(CustomerCreditRepository::class);
        $marks = static fn (): array => $connection->fetchAllKeyValue(
            'SELECT id::text, xmax::text FROM customer WHERE id IN (?, ?)',
            [$kept->getId()->toRfc4122(), $merged->getId()->toRfc4122()],
        );
        $before = $marks();

        $after = static::getContainer()->get(Transactions::class)->run(static function () use ($credits, $company, $merged, $marks): array {
            $credits->lockedBalance($company->getId(), $merged->getId());

            return $marks();
        });

        $moved = static fn (string $id): bool => ($before[$id] ?? null) !== ($after[$id] ?? null);
        self::assertTrue($moved($kept->getId()->toRfc4122()), 'the row of the customer kept is locked');
        self::assertFalse($moved($merged->getId()->toRfc4122()), 'the row of the customer merged away is not');
    }
}
