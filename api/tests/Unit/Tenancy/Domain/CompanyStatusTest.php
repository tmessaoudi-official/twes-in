<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyClosedDown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
final class CompanyStatusTest extends TestCase
{
    public function testACompanyAnOperatorCreatesWaitsForItsOwner(): void
    {
        self::assertSame(Company::STATUS_PENDING, self::pending()->getStatus());
    }

    public function testTheSeededCompanyIsActiveFromTheStart(): void
    {
        self::assertSame(Company::STATUS_ACTIVE, self::company()->getStatus());
    }

    public function testAPendingCompanyBecomesActive(): void
    {
        $company = self::pending();
        $company->activate();

        self::assertSame(Company::STATUS_ACTIVE, $company->getStatus());
    }

    public function testActivatingAnActiveCompanyChangesNothing(): void
    {
        $company = self::company();
        $company->activate();

        self::assertSame(Company::STATUS_ACTIVE, $company->getStatus());
    }

    public function testASuspendedCompanyCanBeActivatedAgain(): void
    {
        $company = self::company();
        $company->suspend();
        self::assertSame(Company::STATUS_SUSPENDED, $company->getStatus());

        $company->activate();
        self::assertSame(Company::STATUS_ACTIVE, $company->getStatus());
    }

    public function testSuspendingRecordsTheChange(): void
    {
        $company = self::company();
        $before = $company->getUpdatedAt();
        $company->suspend(new \DateTimeImmutable('+1 hour'));

        self::assertGreaterThan($before, $company->getUpdatedAt());
    }

    public function testOnlyAnActiveCompanyIsUsable(): void
    {
        self::assertTrue(self::company()->isActive());
        self::assertFalse(self::pending()->isActive());
    }

    public function testAClosedCompanyIsKeptUntilTheDayFixedWhenItClosed(): void
    {
        $company = self::company();
        $company->closeDown(new \DateTimeImmutable('2026-10-13 10:00'), new \DateTimeImmutable('2036-12-31'));

        self::assertSame(Company::STATUS_CLOSED, $company->getStatus());
        self::assertFalse($company->isActive());
        self::assertEquals(new \DateTimeImmutable('2036-12-31'), $company->getRetainUntil());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 10:00'), $company->getClosedDownAt());
    }

    public function testNothingButItsReopeningBringsAClosedCompanyBack(): void
    {
        foreach (['activate', 'suspend'] as $change) {
            $company = self::closed();
            try {
                $company->{$change}();
                self::fail("$change moved a closed company");
            } catch (CompanyClosedDown) {
            }
            self::assertSame(Company::STATUS_CLOSED, $company->getStatus(), $change);
        }

        $company = self::closed();
        $company->reopen(new \DateTimeImmutable());
        self::assertTrue($company->isActive());
        self::assertNull($company->getRetainUntil());
        self::assertNull($company->getClosedDownAt());
    }

    public function testOnlyAWorkingCompanyIsClosedDown(): void
    {
        foreach ([self::pending(), self::closed()] as $company) {
            $status = $company->getStatus();
            try {
                $company->closeDown(new \DateTimeImmutable(), new \DateTimeImmutable('2036-12-31'));
                self::fail("a $status company was closed down");
            } catch (\DomainException) {
            }
            self::assertSame($status, $company->getStatus());
        }
    }

    public function testAClosedCompanyKeepsReadingAndItsBooksOpenAndNothingElse(): void
    {
        $company = self::closed();

        self::assertTrue($company->permitsWhileClosedDown('invoice.read'));
        self::assertTrue($company->permitsWhileClosedDown('accounting.export'));
        self::assertFalse($company->permitsWhileClosedDown('invoice.issue'));
        self::assertFalse($company->permitsWhileClosedDown('subscription.pay'), 'nothing is paid for any more');
        self::assertFalse($company->permitsWhileClosedDown('*'));
    }

    private static function closed(): Company
    {
        $company = self::company();
        $company->closeDown(new \DateTimeImmutable(), new \DateTimeImmutable('2036-12-31'));

        return $company;
    }

    private static function company(): Company
    {
        return new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    private static function pending(): Company
    {
        return Company::pending('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
