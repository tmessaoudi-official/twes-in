<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The locked customer screen holds the whole sign-in, not one tab (docs/SPEC.md § 7, 2026-10-06 19:44): while it is on,
 * the session answers only what the screen reads and the way out, so another tab, a new one or a direct call gets
 * nothing else until the clerk proves who they are again.
 */
final class CustomerScreenLockTest extends ApiTestCase
{
    private const string LOCK = '/api/auth/customer-screen';
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('clerk@twes.local', self::PASSWORD, $this->company, ['company.read', 'customer.read', 'product.read'], 'clerk');
        $this->login('clerk@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    public function testWhileLockedOnlyTheScreenAndTheWayOutAnswer(): void
    {
        $this->getJson($this->companyPath().'/customers');
        self::assertResponseIsSuccessful('unlocked, the clerk reads their customers');

        $this->postJson($this->companyPath().'/customer-screen/lock', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame($this->company->getId()->toRfc4122(), $this->json()['customerScreenCompanyId'] ?? null, 'every tab learns it from who it is');
        $this->getJson($this->companyPath().'/customer-screen/products?q=x');
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/establishments');
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/settings?chain=presentation');
        self::assertResponseIsSuccessful();

        foreach ([
            ['GET', $this->companyPath().'/customers'],
            ['GET', $this->companyPath().'/settings'],
            ['GET', '/api/me/companies'],
            ['POST', $this->companyPath().'/customer-screen/lock'],
        ] as [$method, $path]) {
            $this->sendJson($method, $path);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, "$method $path");
            self::assertSame('customer_screen_locked', $this->json()['error'] ?? null, "$method $path");
        }
    }

    public function testTheLockHoldsTheScreenToItsOwnCompany(): void
    {
        $other = $this->createCompany('Other');
        $this->postJson($this->companyPath().'/customer-screen/lock', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/customer-screen/products?q=x');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOnlyACompanyOfThePersonIsLockedOnto(): void
    {
        $stranger = $this->createCompany('Stranger');

        $this->postJson('/api/companies/'.$stranger->getId()->toRfc4122().'/customer-screen/lock', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->companyPath().'/customers');
        self::assertResponseIsSuccessful('a refused lock locks nothing');
    }

    public function testLeavingTakesAFreshProofAndSpendsIt(): void
    {
        $this->postJson($this->companyPath().'/customer-screen/lock', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->sendJson('DELETE', self::LOCK);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'no proof yet');
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', self::LOCK);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->companyPath().'/customers');
        self::assertResponseIsSuccessful('left, the clerk has the app again');
        $this->getJson('/api/auth/me');
        self::assertArrayHasKey('customerScreenCompanyId', $this->json());
        self::assertNull($this->json()['customerScreenCompanyId']);

        $this->postJson($this->companyPath().'/customer-screen/lock', null);
        $this->sendJson('DELETE', self::LOCK);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'the proof that let them out once is spent');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
