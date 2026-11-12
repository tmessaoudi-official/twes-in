<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Infrastructure\Invitation\InvitationToMail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
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
        $this->deliverQueued();
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

    /**
     * Accepting opens a company still waiting, never one an operator closed since the invitation went out: the refusal
     * or the suspension stands, with its reason, and the new owner reads it at sign-in.
     */
    public function testAcceptingAfterTheCompanyWasRefusedOrSuspendedLeavesItClosed(): void
    {
        $refused = $this->pendingCompany();
        $suspended = $this->createCompany('Acme');
        $this->signedInAsOperator();
        $this->postJson($this->ownersOf($refused), ['email' => 'nadia@example.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $refusedToken = $this->tokenOfTheLastMail();
        $this->postJson($this->ownersOf($suspended), ['email' => 'karim@example.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $suspendedToken = $this->tokenOfTheLastMail();
        $this->postJson('/api/platform/companies/'.$refused->getId()->toRfc4122().'/reject', ['reason' => 'Matricule introuvable']);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/platform/companies/'.$suspended->getId()->toRfc4122().'/reject', ['reason' => 'Facture impayée']);
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();

        $this->postJson('/api/invitations/'.$refusedToken.'/accept', ['displayName' => 'Nadia', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->client->getCookieJar()->clear();
        $this->postJson('/api/invitations/'.$suspendedToken.'/accept', ['displayName' => 'Karim', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame(
            [
                ['name' => 'Acme', 'status' => 'suspended', 'suspension_reason' => 'Facture impayée', 'opened' => true],
                ['name' => 'Globex', 'status' => 'suspended', 'suspension_reason' => 'Matricule introuvable', 'opened' => false],
            ],
            $this->em()->getConnection()->fetchAllAssociative(
                'SELECT name, status, suspension_reason, approved_at IS NOT NULL AS opened FROM company WHERE id IN (?, ?) ORDER BY name',
                [$refused->getId()->toRfc4122(), $suspended->getId()->toRfc4122()],
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
        self::assertSame([], $this->queued());
        self::assertNull(self::getMailerMessage());
    }

    public function testACompanyThatStillHasAnOwnerIsNotGivenAnotherFromThePlatform(): void
    {
        // Operators keep no standing access: owning a working company is its owners' to give, through their own invitations.
        $company = $this->createCompany('Acme');
        $this->createUser('owner@example.test', self::PASSWORD, $company, ['*'], Role::OWNER);
        $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'op@twes.local']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        // Said as the company's own state, never as an address that already belongs to it.
        self::assertSame([['propertyPath' => '', 'reason' => 'company_has_owners', 'params' => []]], $this->refusals());
        self::assertSame([], $this->queued());
        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM invitation'));
    }

    public function testAWorkingCompanyWhoseOwnersAreGoneIsGivenOne(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('manager@example.test', self::PASSWORD, $company, ['customer.read'], 'manager');
        $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'nadia@example.test']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testTheAddressMustBeOne(): void
    {
        $company = $this->pendingCompany();
        $this->signedInAsOperator();

        $this->postJson($this->ownersOf($company), ['email' => 'not an address']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Said on the address's own field, with the form an address takes and an example.
        self::assertSame([['propertyPath' => 'email', 'reason' => 'invalid_email', 'params' => []]], $this->refusals());
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

    /**
     * An owner invited at a mistyped address is taken back from the platform: its link stops working at once, before the
     * wrong person can join as owner, and the withdrawal is audited as the operator's.
     */
    public function testAnOperatorWithdrawsAnOwnerInvitationAndItsLinkStopsWorking(): void
    {
        $company = $this->pendingCompany();
        $operator = $this->signedInAsOperator();
        $this->postJson($this->ownersOf($company), ['email' => 'nadia@gmial.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->tokenOfTheLastMail();

        $invitation = $this->ownerInvitationsOf($company)[0] ?? [];
        self::assertSame('nadia@gmial.test', $invitation['email'] ?? null);
        self::assertIsString($invitation['id'] ?? null, 'the platform view names each invitation');
        $this->sendJson('DELETE', $this->ownerInvitation($company, $invitation['id']));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->ownerInvitationsOf($company));
        self::assertSame(
            [['company_id' => $company->getId()->toRfc4122(), 'actor_user_id' => $operator->getId()->toRfc4122()]],
            $this->em()->getConnection()->fetchAllAssociative("SELECT company_id, actor_user_id FROM audit_log WHERE action = 'invitation.withdrawn'"),
        );
        $this->client->getCookieJar()->clear();
        $this->getJson('/api/invitations/'.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * An owner invitation the worker gave up mailing is said as not sent beside the company, where the operator sends it
     * again, never as sent and waiting.
     */
    public function testAnOwnerInvitationTheWorkerCouldNotMailIsSaidAsNotSent(): void
    {
        $company = $this->pendingCompany();
        $this->signedInAsOperator();
        $this->postJson($this->ownersOf($company), ['email' => 'nadia@carthage.test']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invitation = $this->ownerInvitationsOf($company)[0] ?? [];
        self::assertFalse($invitation['mailFailed'] ?? null, 'a mail on its way has not failed');
        self::assertIsString($invitation['id'] ?? null);

        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerMessageFailedEvent(
            new Envelope(new InvitationToMail($invitation['id']), [new ReceivedStamp('async'), new RedeliveryStamp(3)]),
            'async',
            new \RuntimeException('The mail server is unreachable.'),
        ));

        self::assertTrue($this->ownerInvitationsOf($company)[0]['mailFailed'] ?? null);
    }

    /** The platform takes back an owner's invitation only: a member's is the company's to withdraw, and reads as none. */
    public function testThePlatformWithdrawsNoInvitationButAnOwners(): void
    {
        $company = $this->createCompany('Initech');
        $this->createUser('boss@initech.test', self::PASSWORD, $company, ['*'], Role::OWNER);
        $this->login('boss@initech.test', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/companies/'.$company->getId()->toRfc4122().'/members', ['email' => 'clerk@initech.test', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $memberInvitation = $this->em()->getConnection()->fetchOne('SELECT id FROM invitation WHERE company_id = ?', [$company->getId()->toRfc4122()]);
        self::assertIsString($memberInvitation);
        $this->client->getCookieJar()->clear();
        $this->signedInAsOperator();

        $this->sendJson('DELETE', $this->ownerInvitation($company, $memberInvitation));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([$memberInvitation], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM invitation WHERE company_id = ?', [$company->getId()->toRfc4122()]), 'the member invitation stands');
    }

    private function signedInAsOperator(): User
    {
        $operator = $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        // Every platform write waits for a recent proof (docs/SPEC.md § 7).
        $this->stepUp(self::PASSWORD);

        return $operator;
    }

    private function pendingCompany(): Company
    {
        $company = Company::pending('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis', self::heldNow());
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    private function ownersOf(Company $company): string
    {
        return '/api/platform/companies/'.$company->getId()->toRfc4122().'/owners';
    }

    private function ownerInvitation(Company $company, string $invitationId): string
    {
        return '/api/platform/companies/'.$company->getId()->toRfc4122().'/owner-invitations/'.$invitationId;
    }

    /** @return list<array<array-key, mixed>> the company's owner invitations as the platform lists them */
    private function ownerInvitationsOf(Company $company): array
    {
        $this->getJson('/api/platform/companies');
        self::assertResponseIsSuccessful();
        foreach ($this->jsonList() as $row) {
            if (($row['id'] ?? null) === $company->getId()->toRfc4122()) {
                self::assertIsArray($row['ownerInvitations']);

                return array_values(array_filter($row['ownerInvitations'], \is_array(...)));
            }
        }
        self::fail('the company is not on the platform list');
    }

    private function tokenOfTheLastMail(): string
    {
        $this->deliverQueued();
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $found = [];
        if (1 !== preg_match('#/invitations/([0-9a-f]{64})#', (string) $message->getHtmlBody(), $found)) {
            self::fail('the invitation mail carries no usable link');
        }

        return $found[1];
    }
}
