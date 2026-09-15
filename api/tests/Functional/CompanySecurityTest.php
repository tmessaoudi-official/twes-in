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
 * Whether a company requires its members to sign in with a second factor (docs/SPEC.md § 3 Auth). Read with
 * `company.read`, changed with `company.settings`. Turning it on holds back every member without a factor until they
 * enrol, the one who turned it on included.
 */
final class CompanySecurityTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const string CHANGED = "SELECT COUNT(*) FROM audit_log WHERE action = 'company.mfa_required_changed'";

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAReaderSeesWhetherASecondFactorIsRequired(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        self::assertFalse($this->boolAt($this->json(), 'mfaRequired'));
        self::assertFalse($this->boolAt($this->json(), 'writable'));
    }

    public function testAnAdministratorRequiresASecondFactorAndTheChangeIsAudited(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), ['mfaRequired' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));
        self::assertTrue($this->boolAt($this->json(), 'writable'));
        self::assertTrue($this->companyNow()->isMfaRequired());
        self::assertSame(1, $this->auditedChanges());
    }

    public function testSayingWhatIsAlreadySoChangesNothingAndRecordsNothing(): void
    {
        // Asked of a company that already does not require it: once it is required, this administrator, who has no
        // factor, would be refused before reaching the endpoint at all.
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), ['mfaRequired' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->boolAt($this->json(), 'mfaRequired'));
        self::assertSame(0, $this->auditedChanges());
    }

    public function testOnceRequiredAnAccountWithoutASecondFactorIsHeldBackUntilItEnrols(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->sendJson('PUT', $this->path(), ['mfaRequired' => true]);
        self::assertResponseIsSuccessful();

        // The administrator has no factor either: their very next request is told to enrol.
        $this->getJson('/api/me/companies');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'));
    }

    public function testARequirementThatIsNotABooleanIsRefusedAndChangesNothing(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), ['mfaRequired' => 'yes']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY]);
        self::assertFalse($this->companyNow()->isMfaRequired());
    }

    public function testAReaderMayNotChangeIt(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path(), ['mfaRequired' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertFalse($this->companyNow()->isMfaRequired());
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', '/api/companies/'.$globex->getId()->toRfc4122().'/security', ['mfaRequired' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', self::PASSWORD, $this->company, $permissions, 'admin');
        $this->login('admin@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** Read back from the database: the test client reboots the kernel, so the entity in hand is stale. */
    private function companyNow(): Company
    {
        $this->em()->clear();
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function auditedChanges(): int
    {
        $count = $this->em()->getConnection()->fetchOne(self::CHANGED);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/security';
    }
}
