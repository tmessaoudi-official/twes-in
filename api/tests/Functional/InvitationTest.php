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
use Symfony\Component\HttpFoundation\Response;
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

    /** Invites through the API as an operator and pulls the raw token out of the mail that went out. */
    private function inviteAndReadTheToken(?Company $company = null, string $role = Role::MEMBER): string
    {
        $company ??= $this->company;
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        $this->postJson('/api/companies/'.$company->getId()->toRfc4122().'/members', [
            'email' => 'stranger@twes.local',
            'role' => $role,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('invited', $this->json()['status']);

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
