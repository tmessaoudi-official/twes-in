<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * An operator opens a company and invites its first owner from the platform (docs/SPEC.md § 7, 2026-09-15, C7), without
 * ever being a member of it: the owner's invitation goes out like any other, and accepting it opens the company.
 */
final class PlatformOwnersTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testAnOperatorInvitesTheOwnerOfACompanyTheyDoNotBelongTo(): void
    {
        $company = $this->pendingCompany();
        $operator = $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'nadia@example.test']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['email' => 'nadia@example.test', 'role' => Role::OWNER, 'status' => 'invited'], $this->json());
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame('nadia@example.test', $message->getTo()[0]->getAddress());
        self::assertSame(
            [['company_id' => $company->getId()->toRfc4122(), 'actor_user_id' => $operator->getId()->toRfc4122()]],
            $this->em()->getConnection()->fetchAllAssociative(
                "SELECT company_id, actor_user_id FROM audit_log WHERE action = 'invitation.sent'",
            ),
        );
    }

    public function testAcceptingTheInvitationMakesThemTheOwnerAndOpensTheCompany(): void
    {
        $company = $this->pendingCompany();
        $this->signedInAsOperator();
        $this->postJson($this->ownersOf($company), ['email' => 'nadia@example.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->tokenOfTheLastMail();
        $this->client->getCookieJar()->clear();

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'Nadia', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            [['role' => Role::OWNER, 'status' => 'active']],
            $this->em()->getConnection()->fetchAllAssociative(
                'SELECT r.name AS role, c.status FROM membership m JOIN role r ON r.id = m.role_id JOIN company c ON c.id = m.company_id WHERE c.id = ?',
                [$company->getId()->toRfc4122()],
            ),
        );
    }

    public function testAnAddressThatAlreadyBelongsToTheCompanyIsRefused(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('nadia@example.test', self::PASSWORD, $company, ['*'], Role::OWNER);
        $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'nadia@example.test']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertNull(self::getMailerMessage());
    }

    public function testTheAddressMustBeOne(): void
    {
        $company = $this->pendingCompany();
        $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'not an address']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownOrMalformedCompanyIsNotFound(): void
    {
        $this->signedInAsOperator();

        $this->postJson('/api/platform/companies/0199a1b2-0000-7000-8000-000000000000/owners', ['email' => 'nadia@example.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postJson('/api/platform/companies/not-a-uuid/owners', ['email' => 'nadia@example.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyOwnerDoesNotInviteThroughThePlatform(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@example.test', self::PASSWORD, $company, ['*'], Role::OWNER);
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->postJson($this->ownersOf($company), ['email' => 'nadia@example.test']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function signedInAsOperator(): User
    {
        $operator = $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $operator;
    }

    private function pendingCompany(): Company
    {
        $company = Company::pending('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    private function ownersOf(Company $company): string
    {
        return '/api/platform/companies/'.$company->getId()->toRfc4122().'/owners';
    }

    private function tokenOfTheLastMail(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $found = [];
        if (1 !== preg_match('#/invitations/([0-9a-f]{64})#', (string) $message->getHtmlBody(), $found)) {
            self::fail('the invitation mail carries no usable link');
        }

        return $found[1];
    }
}
