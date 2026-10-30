<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Shared\Application\RealtimePublisher;
use App\Tenancy\Application\Invitation\TellRunOutInvitations;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/** The members of a company: listed, added and removed only by someone that company is any business of. */
final class MembersTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
    }

    public function testAnAdminListsTheMembers(): void
    {
        $this->adminSignedIn();

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame('admin@twes.local', $rows[0]['email']);
    }

    public function testAnAddressThatAlreadyHasAnAccountIsInvitedLikeAnyOther(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('joiner@twes.local', $body['email']);
        self::assertSame(Role::MEMBER, $body['role']);
        self::assertSame('invited', $body['status']);
        self::assertNull($body['userId']);
        self::assertSame(1, $this->deliverQueued(), 'the worker mails it');
        self::assertEmailCount(1);
        // Not a member until they accept: the list shows the invitation, naming nobody.
        $this->getJson($this->path());
        $rows = $this->jsonList();
        self::assertCount(2, $rows);
        self::assertSame(['joiner@twes.local', 'invited', null, null], [$rows[1]['email'], $rows[1]['status'], $rows[1]['userId'] ?? null, $rows[1]['displayName'] ?? null]);
    }

    public function testAnAddressWithNoAccountIsInvitedInstead(): void
    {
        $this->adminSignedIn();

        $this->postJson($this->path(), ['email' => 'stranger@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('invited', $body['status']);
        self::assertNull($body['userId']);
        self::assertSame(1, $this->deliverQueued(), 'the worker mails it');
        self::assertEmailCount(1);
    }

    public function testAnInvitationThatExpiredOrWasUsedIsNoLongerListed(): void
    {
        $this->adminSignedIn();
        foreach (['expired@twes.local', 'used@twes.local'] as $address) {
            $this->postJson($this->path(), ['email' => $address, 'role' => Role::MEMBER]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE invitation SET expires_at = '2020-01-01 00:00:00' WHERE email = 'expired@twes.local'");
        $connection->executeStatement("UPDATE invitation SET accepted_at = NOW() WHERE email = 'used@twes.local'");

        $this->getJson($this->path());

        self::assertSame(['admin@twes.local'], array_map(static fn (array $row) => $row['email'], $this->jsonList()));
    }

    /** One that ran out lately stays listed as expired, until when it ran, and sending it again opens it once more. */
    public function testAnInvitationThatRanOutLatelyIsListedAsExpiredAndSentAgain(): void
    {
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'late@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->em()->getConnection()->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '8 days' WHERE email = 'late@twes.local'");

        $this->getJson($this->path());

        $late = array_values(array_filter($this->jsonList(), static fn (array $row): bool => 'late@twes.local' === ($row['email'] ?? null)));
        self::assertCount(1, $late);
        self::assertSame('expired', $late[0]['status'] ?? null);
        self::assertIsString($late[0]['expiresAt'] ?? null);

        $this->postJson($this->path(), ['email' => 'late@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(Role::MEMBER, $this->invitedRow('late@twes.local')['role']);
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM invitation WHERE email = 'late@twes.local'"), 'the expired one replaced');
    }

    /** Nobody looks at the list to see a link run out: whoever sent it is told once, and may send it again. */
    public function testWhoeverSentAnInvitationIsToldOnceWhenItRanOutUnused(): void
    {
        $this->adminSignedIn();
        foreach (['late@twes.local', 'open@twes.local', 'used@twes.local'] as $address) {
            $this->postJson($this->path(), ['email' => $address, 'role' => Role::MEMBER]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '8 days' WHERE email IN ('late@twes.local', 'used@twes.local')");
        $connection->executeStatement("UPDATE invitation SET accepted_at = expires_at - INTERVAL '1 day' WHERE email = 'used@twes.local'");
        $tell = static::getContainer()->get(TellRunOutInvitations::class);

        self::assertSame(1, $tell->handle());
        self::assertSame(0, $tell->handle(), 'told once, whatever runs after');

        $told = $this->toldOf(TellRunOutInvitations::EXPIRED);
        self::assertCount(1, $told);
        self::assertSame($connection->fetchOne("SELECT invited_by_id FROM invitation WHERE email = 'late@twes.local'"), $told[0]['recipient_id']);
        self::assertSame($this->company->getId()->toRfc4122(), $told[0]['company_id']);
        self::assertSame(['company' => 'Acme', 'email' => 'late@twes.local'], $told[0]['payload']);
    }

    public function testAnInvitationWhoseSenderLeftRunsOutUntold(): void
    {
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'late@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '8 days'");
        $connection->executeStatement('DELETE FROM membership WHERE user_id = (SELECT invited_by_id FROM invitation)');

        self::assertSame(0, static::getContainer()->get(TellRunOutInvitations::class)->handle());

        self::assertSame([], $this->toldOf(TellRunOutInvitations::EXPIRED), 'the company is no longer theirs to hear of');
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM invitation WHERE run_out_told_at IS NOT NULL'), 'and it is not asked again');
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.expired'"), 'the company still hears it ran out');
    }

    /** A members page left open turns the invitation « expirée » as it runs out: the run says it to the company as a change of it. */
    public function testAnInvitationRunningOutIsSaidToItsCompanyAsAChangeOfIt(): void
    {
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'late@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '8 days'");
        $id = $connection->fetchOne("SELECT id FROM invitation WHERE email = 'late@twes.local'");
        // A kernel of its own, as the worker's, whose live changes the recorder hears.
        static::ensureKernelShutdown();
        $publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $publisher);

        self::assertSame(1, static::getContainer()->get(TellRunOutInvitations::class)->handle());

        $row = $this->em()->getConnection()->fetchAssociative("SELECT entity_type, entity_id, actor_user_id, company_id FROM audit_log WHERE action = 'invitation.expired'");
        self::assertSame(['entity_type' => 'invitation', 'entity_id' => $id, 'actor_user_id' => null, 'company_id' => $this->company->getId()->toRfc4122()], $row);
        $said = array_values(array_filter($publisher->pushed, static fn (array $push): bool => 'invitation.expired' === ($push['data']['action'] ?? null)));
        self::assertContains('company:'.$this->company->getId()->toRfc4122(), array_column($said, 'channel'));
        self::assertSame(['invitation', $id], [$said[0]['data']['kind'] ?? null, $said[0]['data']['id'] ?? null]);
    }

    /** An owner who typed the address wrong takes the invitation back: its link stops working, and the list forgets it. */
    public function testAnInvitationIsWithdrawnAndItsLinkNoLongerWorks(): void
    {
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'mistyped@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invitation = $this->invitedRow('mistyped@twes.local');
        self::assertNotNull($invitation['expiresAt'], 'the row says until when it can be used');

        $this->sendJson('DELETE', $this->invitationPath($invitation['invitationId']));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path());
        self::assertSame(['admin@twes.local'], array_map(static fn (array $row) => $row['email'], $this->jsonList()));
        self::assertSame(0, $this->rowCount("SELECT COUNT(*) FROM invitation WHERE email = 'mistyped@twes.local'"), 'no token of it is left to accept');
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.withdrawn'"));

        $this->sendJson('DELETE', $this->invitationPath($invitation['invitationId']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'withdrawn once');
        $this->sendJson('DELETE', $this->invitationPath(Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Sent again, or to another role, an invitation is replaced: one row, one live link, the role now asked for. */
    public function testInvitingTheSameAddressAgainResendsItAtTheRoleNowChosen(): void
    {
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'again@twes.local', 'role' => Role::MEMBER]);
        self::assertSame(1, $this->deliverQueued(), 'mailed');
        $first = $this->invitedRow('again@twes.local');

        $this->postJson($this->path(), ['email' => 'again@twes.local', 'role' => Role::ACCOUNTANT]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1, $this->deliverQueued(), 'mailed again, with a new link');
        $second = $this->invitedRow('again@twes.local');
        self::assertSame(Role::ACCOUNTANT, $second['role']);
        self::assertNotSame($first['invitationId'], $second['invitationId']);
    }

    public function testAnotherCompanysInvitationIsNotWithdrawnHere(): void
    {
        $theirs = $this->createCompany('Globex');
        $this->createUser('owner@globex.test', 'password-1234', $theirs);
        $this->createAdmin();
        $this->signInAs('owner@globex.test');
        $this->postJson('/api/companies/'.$theirs->getId()->toRfc4122().'/members', ['email' => 'theirs@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->em()->getConnection()->fetchOne("SELECT id FROM invitation WHERE email = 'theirs@twes.local'");
        self::assertIsString($id);

        $this->signInAs('admin@twes.local');
        $this->sendJson('DELETE', $this->invitationPath($id));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM invitation WHERE email = 'theirs@twes.local'"));
    }

    /** Whoever may not give a role may not take back the invitation to it either. */
    public function testAnAdminDoesNotWithdrawAnInvitationToBeAnOwner(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->createAdmin();
        $this->signInAs('owner@twes.local');
        $this->postJson($this->path(), ['email' => 'co-owner@twes.local', 'role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->invitedRow('co-owner@twes.local')['invitationId'];

        $this->signInAs('admin@twes.local');
        $this->sendJson('DELETE', $this->invitationPath($id));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAPlainMemberMayNotWithdrawAnInvitation(): void
    {
        $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->adminSignedIn();
        $this->postJson($this->path(), ['email' => 'kept@twes.local', 'role' => Role::MEMBER]);
        $id = $this->invitedRow('kept@twes.local')['invitationId'];
        $this->signInAs('plain@twes.local');

        $this->sendJson('DELETE', $this->invitationPath($id));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A member's role changes where they are listed, without removing them and inviting them again (hunt F5): the
     * journal says from what to what, and the session of the person moved reads its new rights on the next request.
     */
    public function testTheOwnerChangesAMembersRoleAndTheJournalSaysFromWhatToWhat(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $clerk = $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['sale.write'], Role::CLERK);
        $this->signInAs('owner@twes.local');

        $this->sendJson('PUT', $this->rolePath($clerk), ['role' => Role::ADMIN]);

        self::assertResponseIsSuccessful();
        self::assertSame(Role::ADMIN, $this->json()['role']);
        self::assertSame(Role::ADMIN, $this->em()->getConnection()->fetchOne('SELECT r.name FROM membership m JOIN role r ON r.id = m.role_id WHERE m.user_id = ?', [$clerk->getId()->toRfc4122()]));
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes FROM audit_log WHERE action = 'membership.role_changed'");
        self::assertIsString($changes);
        // A jsonb column keeps its keys in its own order.
        self::assertEquals(['user_id' => $clerk->getId()->toRfc4122(), 'from' => Role::CLERK, 'to' => Role::ADMIN], json_decode($changes, true, 4, \JSON_THROW_ON_ERROR));

        $this->sendJson('PUT', $this->rolePath($clerk), ['role' => Role::ADMIN]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM audit_log WHERE action = 'membership.role_changed'"), 'the same role again changes nothing');
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM inbox_item WHERE type = 'membership.role_changed'"), 'nor tells anything');
    }

    public function testThePersonWhoseRoleChangedIsToldTheNewOneInTheirCompany(): void
    {
        // Their rights changed under them: a screen they used may be gone, or a new one there, and nobody said so.
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $clerk = $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['sale.write'], Role::CLERK);
        $this->signInAs('owner@twes.local');

        $this->sendJson('PUT', $this->rolePath($clerk), ['role' => Role::ADMIN]);
        self::assertResponseIsSuccessful();

        $told = $this->toldOf('membership.role_changed');
        self::assertCount(1, $told, 'the person alone, not the owner who changed it');
        self::assertSame($clerk->getId()->toRfc4122(), $told[0]['recipient_id']);
        self::assertSame($this->company->getId()->toRfc4122(), $told[0]['company_id'], 'told in the company it is about, so it leaves with their membership');
        self::assertSame(['company' => 'Acme', 'label' => Role::ADMIN, 'label_key' => 'roles.admin'], $told[0]['payload'], 'a built-in role is named in the reader’s language');

        $this->customRole('barista');
        $this->sendJson('PUT', $this->rolePath($clerk), ['role' => 'barista']);
        self::assertResponseIsSuccessful();
        self::assertSame(['company' => 'Acme', 'label' => 'barista'], $this->toldOf('membership.role_changed')[1]['payload'], 'the company’s own role by the name it gave it');
    }

    public function testThePersonRemovedIsToldTheyNoLongerBelongToTheCompany(): void
    {
        $joiner = $this->createUser('joiner@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->adminSignedIn();

        $this->sendJson('DELETE', $this->path().'/'.$joiner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $told = $this->toldOf('membership.removed');
        self::assertCount(1, $told, 'the person alone');
        self::assertSame($joiner->getId()->toRfc4122(), $told[0]['recipient_id']);
        // About the account: a company's notice is shown only to its members, which they no longer are.
        self::assertNull($told[0]['company_id']);
        self::assertSame(['company' => 'Acme'], $told[0]['payload']);

        $this->client->getCookieJar()->clear();
        $this->login('joiner@twes.local', 'password-1234');
        $this->getJson('/api/me/notifications');
        self::assertResponseIsSuccessful();
        $items = $this->arrayAt($this->json(), 'items');
        self::assertSame(['membership.removed'], array_column($items, 'type'), 'their bell still says it');
    }

    /** @return list<array{recipient_id: string, company_id: ?string, payload: array<mixed>}> */
    private function toldOf(string $type): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT recipient_id, company_id, payload FROM inbox_item WHERE type = ? ORDER BY created_at, id', [$type]);

        return array_map(static function (array $row): array {
            self::assertIsString($row['recipient_id']);
            self::assertTrue(null === $row['company_id'] || \is_string($row['company_id']));
            self::assertIsString($row['payload']);
            $payload = json_decode($row['payload'], true, 8, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            ksort($payload);

            return ['recipient_id' => $row['recipient_id'], 'company_id' => $row['company_id'], 'payload' => $payload];
        }, $rows);
    }

    /** What an admin may not grant or remove, they may not change either. */
    public function testAnAdminChangesNoRoleAboveTheirOwn(): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $member = $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $other = $this->builtInMember('other-admin@twes.local', Role::ADMIN);
        $this->adminSignedIn();

        $this->sendJson('PUT', $this->rolePath($member), ['role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'never raised above an admin');
        $this->sendJson('PUT', $this->rolePath($other), ['role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'another admin is not theirs to lower');
        $this->sendJson('PUT', $this->rolePath($owner), ['role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->sendJson('PUT', $this->rolePath($member), ['role' => Role::ACCOUNTANT]);
        self::assertResponseIsSuccessful();
    }

    /** A company keeps an owner: the last one is never lowered, but an owner steps down beside another (hunt F7). */
    public function testTheLastOwnerIsNeverLoweredAndAnOwnerStepsDownBesideAnother(): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $heir = $this->builtInMember('heir@twes.local', Role::MEMBER);
        $this->signInAs('owner@twes.local');

        $this->sendJson('PUT', $this->rolePath($owner), ['role' => Role::ADMIN]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([['propertyPath' => 'role', 'reason' => 'last_owner', 'params' => []]], $this->refusals());

        $this->sendJson('PUT', $this->rolePath($heir), ['role' => Role::OWNER]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->rolePath($owner), ['role' => Role::ADMIN]);
        self::assertResponseIsSuccessful();
    }

    public function testARoleChangeAnswersAsAStrangerOutsideItsCompanyAndRefusesAnInventedRole(): void
    {
        $theirs = $this->createCompany('Globex');
        $stranger = $this->createUser('owner@globex.test', 'password-1234', $theirs);
        $plain = $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->signInAs('owner@twes.local');

        $this->sendJson('PUT', $this->rolePath($stranger), ['role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'not a member here');
        $this->sendJson('PUT', $this->rolePath($plain), ['role' => 'emperor']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['invalid_value', []], $this->refusalOf('role'), 'said on its field, as the screen reads a refusal');

        $this->signInAs('plain@twes.local');
        $this->sendJson('PUT', $this->rolePath($plain), ['role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'without the right to manage members, as a stranger');
    }

    public function testAMalformedAddressIsRefused(): void
    {
        $this->adminSignedIn();

        // Each refused with the reason the screen words with the format and an example, the one rule of every address.
        foreach (['not-an-address', 'joiner@twes', 'joiner@twes..local', 'é@twes.local'] as $email) {
            $this->postJson($this->path(), ['email' => $email, 'role' => Role::MEMBER]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $email);
            self::assertSame(['invalid_email', []], $this->refusalOf('email'), $email);
        }
    }

    public function testAnInventedRoleIsRefused(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => 'emperor']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testSomebodyIsInvitedAtTheCompanysOwnRole(): void
    {
        // The four built-in names were hardcoded here while the company could already make roles of its own, so a
        // role it made was offered nowhere and could be held by nobody (row 104's remainder).
        $this->adminSignedIn();
        $this->customRole('barista');
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => 'barista']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        $roles = [];
        foreach ($this->jsonList() as $row) {
            self::assertIsString($row['email']);
            $roles[$row['email']] = $row['role'];
        }
        self::assertSame('barista', $roles['joiner@twes.local'], 'held, not silently downgraded to member');
    }

    public function testNobodyIsInvitedIntoACustomRoleThatHoldsWhatTheInviterDoesNot(): void
    {
        $this->adminSignedIn();
        $em = $this->em();
        $company = $em->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $em->persist(new Role('cashier', ['customer.read', 'invoice.issue'], $company));
        $em->flush();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => 'cashier']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'the admin lacks invoice.issue, so may not hand it on');
    }

    public function testAnotherCompanysRoleIsNotOfferedHere(): void
    {
        // Same refusal as an invented name: a role row exists, but not one this company may use.
        $this->adminSignedIn();
        $elsewhere = $this->createCompany('Other');
        $this->customRole('their-role', $elsewhere);
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => 'their-role']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** A role of this company (or of $company), as the roles screen would have made it. */
    private function customRole(string $name, ?Company $company = null): Role
    {
        $em = $this->em();
        $owner = $em->find(Company::class, ($company ?? $this->company)->getId());
        self::assertNotNull($owner);
        $role = new Role($name, ['customer.read'], $owner);
        $em->persist($role);
        $em->flush();

        return $role;
    }

    public function testTheSamePersonIsNotAddedTwice(): void
    {
        $this->createUser('joiner@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->adminSignedIn();

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertEmailCount(0);
    }

    public function testAMemberIsRemoved(): void
    {
        $joiner = $this->createUser('joiner@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->adminSignedIn();

        $this->sendJson('DELETE', $this->path().'/'.$joiner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testSomeoneWhoIsNotAMemberCannotBeRemoved(): void
    {
        $this->adminSignedIn();

        $this->sendJson('DELETE', $this->path().'/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $theirs = $this->createCompany('Globex');
        $this->adminSignedIn();

        $this->getJson('/api/companies/'.$theirs->getId()->toRfc4122().'/members');

        // 404, never 403: a 403 would confirm the company exists, which is how a tenant list is enumerated.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyThatDoesNotExistAnswersTheSameWay(): void
    {
        $this->adminSignedIn();

        $this->getJson('/api/companies/'.Uuid::v7()->toRfc4122().'/members');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodyMayBeAddedToACompanyTheCallerIsNotIn(): void
    {
        $theirs = $this->createCompany('Globex');
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson('/api/companies/'.$theirs->getId()->toRfc4122().'/members', ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPlainMemberMayNotManageMembers(): void
    {
        $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->login('plain@twes.local', 'password-1234');

        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnOperatorDoesNotReachTheMembersOfACompanyTheyAreNotAMemberOf(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        $this->getJson($this->path());

        // Operators hold the platform scope and nothing more (docs/SPEC.md § 7, 2026-09-15, S3): a company answers them as a stranger.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Removing someone and leaving are different acts: nobody takes themselves out through « Retirer », the only owner
     * nor one of two, so a slip of the finger never cuts the person's own access. The last owner stays guarded below it.
     */
    public function testTheOnlyOwnerCannotRemoveThemselves(): void
    {
        $this->assertRemovingOneselfIsRefused(1);
    }

    public function testOneOfTwoOwnersCannotRemoveThemselves(): void
    {
        $this->assertRemovingOneselfIsRefused(2);
    }

    private function assertRemovingOneselfIsRefused(int $owners): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        if (2 === $owners) {
            // A second owner shares the first one's role: a company holds one role of each name.
            $role = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $owner])?->getRole();
            self::assertNotNull($role);
            $this->em()->persist(new Membership($this->createUser('second@twes.local', 'password-1234'), $this->company, $role));
            $this->em()->flush();
        }
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');

        $this->sendJson('DELETE', $this->path().'/'.$owner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        // Told apart by its reason, never by a word of its English detail.
        self::assertSame([['propertyPath' => 'member', 'reason' => 'own_membership', 'params' => []]], $this->refusals());
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAMalformedCompanyIdentifierIsNotAnError(): void
    {
        $this->adminSignedIn();

        $this->getJson('/api/companies/not-a-uuid/members');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARefusalIsStillJsonForABrowserThatAcceptsAnything(): void
    {
        $this->adminSignedIn();

        // A browser sends "Accept: */*". With jsonld first in error_formats and jsonld not enabled, that
        // negotiated an unsupported format and every refusal became a 500. An invented role is the refusal
        // used here because an unknown address is no longer one: it becomes an invitation.
        $this->client->request(
            'POST',
            $this->path(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*', 'HTTP_CSRF_TOKEN' => '0123456789abcdef0123456789abcdef'],
            json_encode(['email' => 'joiner@twes.local', 'role' => 'emperor'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAnAdminMayNotGrantOwnerNorRemoveAnOwnerOrAnotherAdmin(): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $member = $this->createUser('member@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->adminSignedIn();
        // A company holds one admin role, which the signed-in admin's creation made: the other admin joins it.
        $em = $this->em();
        $company = $em->find(Company::class, $this->company->getId());
        $adminRole = $em->getRepository(Role::class)->findOneBy(['company' => $company, 'name' => Role::ADMIN]);
        $otherAdmin = $this->createUser('other-admin@twes.local', 'password-1234');
        $otherAdminUser = $em->find(User::class, $otherAdmin->getId());
        self::assertNotNull($company);
        self::assertNotNull($adminRole);
        self::assertNotNull($otherAdminUser);
        $em->persist(new Membership($otherAdminUser, $company, $adminRole));
        $em->flush();

        $this->postJson($this->path(), ['email' => 'next-owner@twes.local', 'role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        foreach ([$owner, $otherAdmin] as $target) {
            $this->sendJson('DELETE', $this->path().'/'.$target->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }

        $this->sendJson('DELETE', $this->path().'/'.$member->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testAnOwnerInvitesAnotherOwner(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');

        $this->postJson($this->path(), ['email' => 'next-owner@twes.local', 'role' => Role::OWNER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * What the admin's role refuses is answered before the proof is asked: nobody proves who they are only to be told
     * no (hunt N-1). What it allows still waits for the proof.
     */
    public function testWhatAnAdminMayNotDoIsRefusedBeforeTheProof(): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $member = $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->createAdmin();
        $this->signInAs('owner@twes.local');
        $this->postJson($this->path(), ['email' => 'co-owner@twes.local', 'role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invitation = $this->invitedRow('co-owner@twes.local')['invitationId'];

        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->rolePath($owner), ['role' => Role::MEMBER]);
        $this->assertRefusedBeforeTheProof('an owner lowered');
        $this->sendJson('PUT', $this->rolePath($member), ['role' => Role::OWNER]);
        $this->assertRefusedBeforeTheProof('a member raised to owner');
        $this->sendJson('DELETE', $this->path().'/'.$owner->getId()->toRfc4122());
        $this->assertRefusedBeforeTheProof('an owner removed');
        $this->sendJson('DELETE', $this->invitationPath($invitation));
        $this->assertRefusedBeforeTheProof('an owner invitation withdrawn');
        $this->postJson($this->path(), ['email' => 'another@twes.local', 'role' => Role::OWNER]);
        $this->assertRefusedBeforeTheProof('an owner invited');

        $this->sendJson('PUT', $this->rolePath($member), ['role' => Role::ACCOUNTANT]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('step_up_required:', $this->stringAt($this->json(), 'detail'), 'what the role allows still waits for the proof');
    }

    /**
     * Inviting an address again replaces its open invitation, which takes that invitation back: whoever may not withdraw
     * an invitation to be an owner may not replace it by one to be a member either.
     */
    public function testAnAdminDoesNotReplaceAnInvitationToBeAnOwner(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->createAdmin();
        $this->signInAs('owner@twes.local');
        $this->postJson($this->path(), ['email' => 'co-owner@twes.local', 'role' => Role::OWNER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->signInAs('admin@twes.local');
        $this->postJson($this->path(), ['email' => 'co-owner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(Role::OWNER, $this->invitedRow('co-owner@twes.local')['role']);
    }

    /**
     * A clerk who locked their password by mistyping it is let back in by their company's owner, without the fifteen
     * minutes or the mailed link (row 241): the list says whose password is locked and until when, lifting it is
     * recorded in the company's journal, and the clerk is told by mail.
     */
    public function testAnOwnerLiftsTheLockOfAMembersPassword(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $clerk = $this->builtInMember('clerk@twes.local', Role::MEMBER);
        $this->lockByWrongPasswords('clerk@twes.local');
        $this->signInAs('owner@twes.local');

        self::assertIsString($this->memberRow('clerk@twes.local')['passwordLockedUntil']);
        self::assertNull($this->memberRow('owner@twes.local')['passwordLockedUntil']);

        $this->postJson($this->unlockPath($clerk), []);

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['passwordLockedUntil']);
        self::assertContains('lock_lifted_by_owner', $this->securityNotices(), 'the clerk is told who let them back in');
        self::assertSame(1, $this->rowCount(\sprintf(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'account.lock_lifted' AND entity_id = '%s' AND company_id = '%s'",
            $clerk->getId()->toRfc4122(),
            $this->company->getId()->toRfc4122(),
        )));
        $this->client->getCookieJar()->clear();
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** The owner's alone, whatever a custom role grants: said before any proof is asked. */
    public function testAnAdminDoesNotLiftALock(): void
    {
        $this->createAdmin();
        $clerk = $this->builtInMember('clerk@twes.local', Role::MEMBER);
        $this->lockByWrongPasswords('clerk@twes.local');
        $this->login('admin@twes.local', 'password-1234');

        // Whoever guessed must not learn that, or until when, they locked it: only its owner, who lifts it, reads it.
        self::assertNull($this->memberRow('clerk@twes.local')['passwordLockedUntil'] ?? null, 'an admin does not read the lock');

        $this->postJson($this->unlockPath($clerk), []);

        $this->assertRefusedBeforeTheProof('an admin lifting a lock');
        $this->client->getCookieJar()->clear();
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'still locked');
    }

    public function testAnOwnerLiftsNoLockOutsideTheirCompany(): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $stranger = $this->createUser('stranger@twes.local', 'password-1234', $this->createCompany('Globex'));
        $this->lockByWrongPasswords('stranger@twes.local');
        $this->signInAs('owner@twes.local');

        $this->postJson($this->unlockPath($stranger), []);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNotContains('lock_lifted_by_owner', $this->securityNotices());
        $this->client->getCookieJar()->clear();
        $this->login('stranger@twes.local', 'password-1234');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'still locked');
    }

    /** Five wrong passwords, which lock the password for the configured while. */
    private function lockByWrongPasswords(string $email): void
    {
        $this->client->getCookieJar()->clear();
        for ($i = 0; $i < 5; ++$i) {
            $this->login($email, 'not-the-password');
        }
        $this->client->getCookieJar()->clear();
    }

    /** @return array<mixed> the member's row the list shows for that address */
    private function memberRow(string $email): array
    {
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        foreach ($this->jsonList() as $row) {
            if ($email === ($row['email'] ?? null) && 'joined' === ($row['status'] ?? null)) {
                return $row;
            }
        }
        self::fail(\sprintf('No member row for %s.', $email));
    }

    private function unlockPath(User $member): string
    {
        return $this->path().'/'.$member->getId()->toRfc4122().'/unlock';
    }

    private function assertRefusedBeforeTheProof(string $what): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $what);
        self::assertStringStartsNotWith('step_up_required:', $this->stringAt($this->json(), 'detail'), $what);
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/members';
    }

    private function invitationPath(string $invitationId): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invitations/'.$invitationId;
    }

    /** @return array{invitationId: string, role: string, expiresAt: string|null} the invited row the list shows for that address */
    private function invitedRow(string $email): array
    {
        $this->getJson($this->path());
        foreach ($this->jsonList() as $row) {
            if ($email === ($row['email'] ?? null) && 'invited' === ($row['status'] ?? null)) {
                self::assertIsString($row['invitationId'] ?? null);
                self::assertIsString($row['role'] ?? null);

                return ['invitationId' => $row['invitationId'], 'role' => $row['role'], 'expiresAt' => \is_string($row['expiresAt'] ?? null) ? $row['expiresAt'] : null];
            }
        }
        self::fail(\sprintf('No invited row for %s.', $email));
    }

    /** A member at one of the built-in roles, which several members share, unlike the role `createUser` makes each. */
    private function builtInMember(string $email, string $roleName): User
    {
        $user = $this->createUser($email, 'password-1234');
        $role = $this->em()->getRepository(Role::class)->findOneBy(['name' => $roleName, 'company' => null]);
        self::assertInstanceOf(Role::class, $role);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->em()->persist(new Membership($user, $company, $role));
        $this->em()->flush();

        return $user;
    }

    private function rolePath(User $member): string
    {
        return $this->path().'/'.$member->getId()->toRfc4122().'/role';
    }

    private function rowCount(string $sql): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsInt($count);

        return $count;
    }

    /** Another person at the same browser: the session moves to them, with a recent proof as changing members asks. */
    private function signInAs(string $email): void
    {
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
        $this->stepUp('password-1234');
    }

    private function createAdmin(): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, ['user.read', 'user.write', 'customer.read'], Role::ADMIN);
    }

    private function adminSignedIn(): void
    {
        $this->createAdmin();
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        // Changing who belongs to the company waits for a recent proof (docs/SPEC.md § 7, the step-up list).
        $this->stepUp('password-1234');
    }
}
