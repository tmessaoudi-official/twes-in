<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\Invitation\InvitationToMail;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * The invitation, end to end through the API: an admin invites an address with no account, the mail carries a
 * link, and the link is opened logged out. Under SameSite=Strict a link from a mail client arrives with no
 * session cookie at all, so every one of these requests is made without signing in first.
 */
final class InvitationTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
    }

    public function testTheLinkDescribesWhatItOffersWithoutAnySession(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->getJson('/api/invitations/'.$token);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('stranger@twes.local', $body['email']);
        self::assertSame('Acme', $body['companyName']);
        self::assertSame(Role::MEMBER, $body['roleName']);
        self::assertFalse($body['hasAccount']);
    }

    public function testTheLinkOfAnAddressWithAnAccountSaysSo(): void
    {
        $this->createUser('stranger@twes.local', 'their-own-password');
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->getJson('/api/invitations/'.$token);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['hasAccount']);
    }

    public function testAnExistingAccountAcceptsWithNothingFilledInAndKeepsItsPassword(): void
    {
        $existing = $this->createUser('stranger@twes.local', 'their-own-password');
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        // Whatever the body says, a link never names or re-keys an account that exists.
        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'Impostor', 'password' => 'an-attacker-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($existing->getId()->toRfc4122(), $this->json()['userId']);
        self::assertNotNull($this->em()->getRepository(Membership::class)->findOneBy(['user' => $existing->getId(), 'company' => $this->company->getId()]));
        $this->login('stranger@twes.local', 'an-attacker-password');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->login('stranger@twes.local', 'their-own-password');
        self::assertResponseIsSuccessful();
    }

    public function testAnExistingAccountNeedsNoBodyAtAll(): void
    {
        $this->createUser('stranger@twes.local', 'their-own-password');
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', []);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testANewAddressAcceptingWithNothingFilledInIsRefusedAndTheLinkStillWorks(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]));
        $this->getJson('/api/invitations/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownLinkIsNotFound(): void
    {
        $this->getJson('/api/invitations/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAMalformedLinkIsNotFoundTheSameWay(): void
    {
        $this->getJson('/api/invitations/not-a-token');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAcceptingCreatesTheAccountAndJoinsTheCompany(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', [
            'displayName' => 'New Person',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Acme', $this->json()['companyName']);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]);
        self::assertNotNull($user);
        self::assertSame('New Person', $user->getDisplayName());
    }

    public function testAnInvitationJoinsTheRoleItWasSentForEvenOnceAnotherRoleTakesItsName(): void
    {
        // Resolving the role by name at the link let an editor invite a second address of their own into a role they
        // may give, rename it away, rename a role holding what they lack onto that name, and join holding it.
        $em = $this->em();
        $company = $em->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $low = new Role('low', ['customer.read'], $company);
        $cashier = new Role('cashier', ['customer.read', 'invoice.issue'], $company);
        $em->persist($low);
        $em->persist($cashier);
        $em->flush();
        $this->createUser('editor@twes.local', 'password-1234', $this->company, ['company.read', 'company.settings', 'user.write', 'customer.read'], 'editor');
        $this->login('editor@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        $companyPath = '/api/companies/'.$this->company->getId()->toRfc4122();
        $this->postJson($companyPath.'/members', ['email' => 'stranger@twes.local', 'role' => 'low']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->deliverQueued();
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $token = self::tokenIn((string) $message->getHtmlBody());

        $this->sendJson('PUT', $companyPath.'/roles/'.$low->getId()->toRfc4122(), ['name' => 'away', 'permissions' => ['customer.read']]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $companyPath.'/roles/'.$cashier->getId()->toRfc4122(), ['name' => 'low', 'permissions' => ['customer.read', 'invoice.issue']]);
        self::assertResponseIsSuccessful();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'Second Me', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]);
        self::assertNotNull($user);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $user->getId(), 'company' => $this->company->getId()]);
        self::assertNotNull($membership);
        self::assertSame('away', $membership->getRole()->getName(), 'the role it was sent for, under its new name, never the one that took its name');
    }

    public function testTheNewAccountCanSignIn(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();
        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'New Person', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->login('stranger@twes.local', self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertSame('Acme', $this->section($this->json(), 'company')['name']);
    }

    public function testTheSameLinkCannotBeUsedTwice(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();
        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'First', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'Second', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAUsedLinkNoLongerDescribesAnything(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();
        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'First', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson('/api/invitations/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAShortPasswordIsRefusedAndNoAccountIsMade(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'New Person', 'password' => 'short']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]));
    }

    public function testABlankNameIsRefused(): void
    {
        $token = $this->inviteAndReadTheToken();
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => '', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnOwnerAcceptingActivatesThePendingCompany(): void
    {
        $pending = $this->em()->getRepository(Company::class)->findOneBy(['name' => 'Waiting'])
            ?? $this->createPendingCompany();
        $token = $this->inviteAndReadTheToken($pending, Role::OWNER);
        $this->signOut();

        $this->postJson('/api/invitations/'.$token.'/accept', ['displayName' => 'The Owner', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $fresh = $this->em()->getRepository(Company::class)->findOneBy(['name' => 'Waiting']);
        self::assertNotNull($fresh);
        self::assertSame(Company::STATUS_ACTIVE, $fresh->getStatus());
    }

    public function testTheRequestQueuesTheInvitationAndTheWorkerMakesItsLinkAndMailsIt(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/members', ['email' => 'stranger@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]);
        self::assertInstanceOf(Invitation::class, $invitation);
        // What waits in the queue is the invitation's id: a link's secret is never stored, the queue included.
        self::assertEquals([new InvitationToMail($invitation->getId()->toRfc4122())], $this->queued());
        self::assertEmailCount(0);
        $placeholder = $invitation->getTokenHash();

        self::assertSame(1, $this->deliverQueued());
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $token = self::tokenIn((string) $message->getHtmlBody());
        self::assertNotSame($placeholder, hash('sha256', $token), 'the link is made by the worker, not kept from the request');

        $this->signOut();
        $this->getJson('/api/invitations/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testAnInvitationReplacedBeforeTheWorkerRanIsNotMailed(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/members', ['email' => 'stranger@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->queued();
        // The test client's kernel starts afresh with each request, its in-memory queue with it: the first invitation's
        // message is kept here and handed over after the second request, as a worker that lagged would.
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/members', ['email' => 'stranger@twes.local', 'role' => Role::ADMIN]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertCount(1, $first);
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($first[0], [new ReceivedStamp('async')]));
        self::assertEmailCount(0);
        self::assertSame(1, $this->deliverQueued());

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $this->signOut();
        $this->getJson('/api/invitations/'.self::tokenIn((string) $message->getHtmlBody()));
        self::assertResponseIsSuccessful();
        self::assertSame(Role::ADMIN, $this->json()['roleName'], 'the one mailed is the invitation that is still open');
    }

    public function testTheMailCarriesTheCompanyAndALink(): void
    {
        $this->inviteAndReadTheToken();

        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame('stranger@twes.local', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('Acme', (string) $message->getSubject());
        self::assertStringContainsString('Acme', (string) $message->getHtmlBody());

        // The deadline is read by a person. Every timestamp is stored UTC, and Acme is Africa/Tunis (+1
        // all year), so the rendered wall-clock time must be the company's, never the stored one.
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => Email::fromString('stranger@twes.local')]);
        self::assertInstanceOf(Invitation::class, $invitation);
        $expiresAt = $invitation->getExpiresAt();
        $html = (string) $message->getHtmlBody();

        self::assertStringContainsString(
            $expiresAt->setTimezone(new \DateTimeZone('Africa/Tunis'))->format('d/m/Y H:i'),
            $html,
        );
        self::assertStringNotContainsString(
            $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y H:i'),
            $html,
        );
    }

    private function createPendingCompany(): Company
    {
        $company = Company::pending('Waiting', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    /**
     * Invites through the API and pulls the raw token out of the mail that went out: an owner invitation the way an
     * operator sends one from the platform, any other role the way the company's own owner does.
     */
    private function inviteAndReadTheToken(?Company $company = null, string $role = Role::MEMBER): string
    {
        $company ??= $this->company;
        if (Role::OWNER === $role) {
            $this->createUser('op@twes.local', 'password-1234', operator: true);
            $this->login('op@twes.local', 'password-1234');
            self::assertResponseIsSuccessful();
            $this->postJson('/api/platform/companies/'.$company->getId()->toRfc4122().'/owners', ['email' => 'stranger@twes.local']);
        } else {
            $this->createUser('owner@twes.local', 'password-1234', $company, ['*'], Role::OWNER);
            $this->login('owner@twes.local', 'password-1234');
            self::assertResponseIsSuccessful();
            $this->postJson('/api/companies/'.$company->getId()->toRfc4122().'/members', [
                'email' => 'stranger@twes.local',
                'role' => $role,
            ]);
        }
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('invited', $this->json()['status']);

        // The worker's part: the request only queued the invitation, and the worker makes its link and mails it.
        $this->deliverQueued();
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);

        return self::tokenIn((string) $message->getHtmlBody());
    }

    /** The raw token exists only in the mail, so this is the only way a test can get hold of one. */
    private static function tokenIn(string $html): string
    {
        $found = [];
        if (1 !== preg_match('#/invitations/([0-9a-f]{64})#', $html, $found)) {
            self::fail('the invitation mail carries no usable link');
        }

        return $found[1];
    }

    /** A link opened from a mail client carries no session cookie; this is how that is reproduced. */
    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }
}
