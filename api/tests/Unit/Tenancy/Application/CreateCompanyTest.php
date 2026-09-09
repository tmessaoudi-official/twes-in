<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCompanies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateCompany::class)]
final class CreateCompanyTest extends TestCase
{
    private InMemoryCompanies $companies;
    private InMemoryAuditTrail $audit;
    private CreateCompany $create;

    protected function setUp(): void
    {
        $this->companies = new InMemoryCompanies();
        $this->audit = new InMemoryAuditTrail();
        $this->create = new CreateCompany($this->companies, $this->audit, new MockClock('2026-09-09 10:00:00'));
    }

    public function testTheCompanyWaitsForItsFirstOwner(): void
    {
        $company = $this->create->handle(self::request(), Uuid::v7());

        self::assertSame(Company::STATUS_PENDING, $company->getStatus());
        self::assertSame('Acme', $company->getName());
        self::assertSame([$company], $this->companies->companies);
    }

    public function testTheCountryAndCurrencyAreStoredUpperCase(): void
    {
        $company = $this->create->handle(new NewCompany('Acme', 'tn', 'tnd', 'fr', 'Africa/Tunis'), null);

        self::assertSame('TN', $company->getCountryCode());
        self::assertSame('TND', $company->getCurrency());
    }

    public function testTwoCompaniesCannotShareAName(): void
    {
        $this->create->handle(self::request(), null);

        $this->expectException(CompanyNameTaken::class);
        $this->create->handle(self::request(), null);
    }

    public function testTheRefusedSecondCompanyIsNotStored(): void
    {
        $this->create->handle(self::request(), null);

        try {
            $this->create->handle(self::request(), null);
        } catch (CompanyNameTaken) {
        }

        self::assertCount(1, $this->companies->companies);
    }

    public function testCreationIsAudited(): void
    {
        $actor = Uuid::v7();
        $company = $this->create->handle(self::request(), $actor);

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame('company', $entry->entityType);
        self::assertSame('company.created', $entry->action);
        self::assertNotNull($entry->entityId);
        self::assertTrue($company->getId()->equals($entry->entityId));
        self::assertSame($actor, $entry->actorUserId);
    }

    private static function request(): NewCompany
    {
        return new NewCompany('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
