<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company that is not active is closed to its members: pending until an operator approves it, suspended after an
 * operator stops it. Its members answer the same 404 as a stranger, so the status of a company they cannot use is
 * not a way to learn anything about it. Operators decide on it from the platform endpoints and reach it no more than a stranger does.
 */
final class CompanyStatusAccessTest extends ApiTestCase
{
    public function testAnActiveCompanyIsOpenToItsMembers(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson($this->profileOf($company));

        self::assertResponseIsSuccessful();
    }

    public function testAPendingCompanyIsClosedToItsOwnMembers(): void
    {
        $company = $this->pendingCompany();
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson($this->profileOf($company));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // The session still describes the company, so the application can say it is waiting for approval.
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame(Company::STATUS_PENDING, $this->section($this->json(), 'company')['status']);
    }

    public function testASuspendedCompanyIsClosedToItsOwnMembers(): void
    {
        $company = $this->createCompany('Acme');
        $company->suspend();
        $this->em()->flush();
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson($this->profileOf($company));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnOperatorDecidesOnAPendingCompanyFromThePlatformWithoutReachingIt(): void
    {
        $company = $this->pendingCompany();
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        $this->getJson($this->profileOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson('/api/platform/companies?status=pending');
        self::assertResponseIsSuccessful();
        self::assertContains('Waiting', array_column($this->jsonList(), 'name'));
    }

    private function pendingCompany(): Company
    {
        $company = Company::pending('Waiting', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    private function profileOf(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/profile';
    }
}
