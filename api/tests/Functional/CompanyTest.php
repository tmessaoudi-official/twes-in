<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\Unit;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/companies: only a platform operator opens a company, and it waits for its first owner. */
final class CompanyTest extends ApiTestCase
{
    private const array VALID = [
        'name' => 'Acme',
        'countryCode' => 'TN',
        'currency' => 'TND',
        'locale' => 'fr',
        'timezone' => 'Africa/Tunis',
    ];

    public function testAnOperatorOpensACompanyThatWaitsForItsOwner(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('Acme', $body['name']);
        self::assertSame(Company::STATUS_PENDING, $body['status']);
        self::assertIsString($body['id']);
    }

    public function testTheCompanyIsReallyStored(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        self::assertNotNull($this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']));
    }

    public function testTheCompanyOpensWithItsCountrysTaxesAndUnits(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        $company = $this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']);
        self::assertNotNull($company);
        self::assertSame('TN', $company->getFiscalPreset());
        self::assertCount(6, $this->em()->getRepository(TaxComponent::class)->findBy(['company' => $company]));
        self::assertCount(8, $this->em()->getRepository(Unit::class)->findBy(['company' => $company]));
    }

    public function testACountryWithNoFiscalPresetIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        // A company there could not invoice without its taxes; a new country is a preset file plus translations.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']));
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnOrdinaryUserMayNotOpenACompany(): void
    {
        $company = $this->createCompany('Existing');
        $this->createUser('member@twes.local', 'password-1234', $company, ['*']);
        $this->login('member@twes.local', 'password-1234');

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnOwnerWildcardDoesNotReachPlatformScope(): void
    {
        $company = $this->createCompany('Existing');
        $this->createUser('owner@twes.local', 'password-1234', $company, ['*']);
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/companies', self::VALID);

        // "*" is a company wildcard. Platform scope sits outside memberships and is not reachable by any role.
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testABlankNameIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'name' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownCountryIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'countryCode' => 'ZZ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownTimezoneIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'timezone' => 'Mars/Olympus']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTwoCompaniesCannotShareAName(): void
    {
        $this->operatorSignedIn();
        $this->postJson('/api/companies', self::VALID);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testTheRequestNeedsItsCsrfHeader(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID, withCsrf: false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function operatorSignedIn(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
