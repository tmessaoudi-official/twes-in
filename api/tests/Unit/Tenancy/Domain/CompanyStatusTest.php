<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Company;
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

    private static function company(): Company
    {
        return new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    private static function pending(): Company
    {
        return Company::pending('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
