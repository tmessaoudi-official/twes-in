<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Every platform write asks the operator to prove who is at the screen again (docs/SPEC.md § 7, the step-up list):
 * deactivating an account or ending its sessions, a subscription, a payment, approving a company and adding its owner,
 * the legal texts, the platform's settings, and creating a company, wherever its path. A stolen operator session reaches
 * every company, so the platform is where a proof matters most. Reads ask nothing, and who is no operator is answered
 * as before, never told to prove who they are.
 */
final class PlatformWritesStepUpTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * Every write under /api/platform the router knows, with ids that name nothing: the proof is asked before anything
     * is looked for, so an operator learns nothing either way without it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function platformWrites(): iterable
    {
        $id = Uuid::v7()->toRfc4122();
        yield 'deactivate an account' => ['POST', "/api/platform/accounts/$id/deactivate"];
        yield 'reactivate an account' => ['POST', "/api/platform/accounts/$id/reactivate"];
        yield 'end an account\'s sessions' => ['POST', "/api/platform/accounts/$id/end-sessions"];
        yield 'approve a company' => ['POST', "/api/platform/companies/$id/approve"];
        yield 'reject a company' => ['POST', "/api/platform/companies/$id/reject"];
        yield 'add an owner' => ['POST', "/api/platform/companies/$id/owners"];
        yield 'set a subscription' => ['PUT', "/api/platform/companies/$id/subscription"];
        yield 'end a subscription' => ['DELETE', "/api/platform/companies/$id/subscription"];
        yield 'confirm a payment' => ['POST', "/api/platform/payment-declarations/$id/confirm"];
        yield 'reject a payment' => ['POST', "/api/platform/payment-declarations/$id/reject"];
        yield 'write a legal text' => ['POST', '/api/platform/legal-texts/mentions/fr/versions'];
        yield 'validate a legal text' => ['POST', '/api/platform/legal-texts/mentions/fr/validate'];
        yield 'change a platform setting' => ['PUT', '/api/platform/settings/support.email'];
        yield 'change several platform settings' => ['PUT', '/api/platform/settings'];
    }

    #[DataProvider('platformWrites')]
    public function testAPlatformWriteWaitsForTheProof(string $method, string $path): void
    {
        $this->sendJson($method, $path, []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->stepUp(self::PASSWORD);
        $this->sendJson($method, $path, []);
        self::assertNotSame('step_up_required', $this->json()['error'] ?? null, 'the proof given, the write is answered on its merits');
    }

    public function testCreatingACompanyWaitsForTheProofAndCreatesNothingBefore(): void
    {
        $company = ['name' => 'Acme', 'countryCode' => 'TN', 'currency' => 'TND', 'locale' => 'fr', 'timezone' => 'Africa/Tunis'];
        $this->postJson('/api/companies', $company);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('step_up_required:', $this->stringAt($this->json(), 'detail'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM company WHERE name = 'Acme'"));

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/companies', $company);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testReadingThePlatformAsksNothing(): void
    {
        $this->client->request('GET', '/api/platform/companies', [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/platform/accounts', [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
    }

    /** Who is no operator meets the platform's writes as before: refused, never asked to prove who they are. */
    public function testWhoIsNoOperatorIsAnsweredAsBefore(): void
    {
        $this->createUser('owner@twes.local', self::PASSWORD, $this->createCompany('Owned'));
        $this->client->getCookieJar()->clear();
        $this->login('owner@twes.local', self::PASSWORD);

        $this->sendJson('PUT', '/api/platform/settings/support.email', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertArrayNotHasKey('error', $this->json());
        $this->postJson('/api/companies', ['name' => 'Acme', 'countryCode' => 'TN', 'currency' => 'TND', 'locale' => 'fr', 'timezone' => 'Africa/Tunis']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $detail = $this->json()['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringStartsNotWith('step_up_required', $detail);
    }
}
