<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Inbox\Domain\Model\InboxItem;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The notification centre over HTTP and the realtime connection token (docs/SPEC.md § 7, 2026-09-13): every
 * read and every mark is the signed-in user's own, and the token lists exactly the channels that user may hear.
 */
final class NotificationsTest extends ApiTestCase
{
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
        $this->owner = $this->createUser('owner@twes.local', 'password-1234', $this->company);
    }

    public function testSomeoneWithAnAccountInvitedToACompanyFindsItInTheirCentre(): void
    {
        $this->createUser('joiner@twes.local', 'password-1234');
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/members', ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->client->getCookieJar()->clear();
        $this->login('joiner@twes.local', 'password-1234');
        $this->getJson('/api/me/notifications');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame(1, $body['unread']);
        $items = $this->arrayAt($body, 'items');
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        self::assertSame('invitation.received', $items[0]['type']);
        self::assertSame(['company_id' => $this->company->getId()->toRfc4122(), 'company' => 'Acme', 'role' => Role::MEMBER], $items[0]['payload']);
        self::assertNull($items[0]['readAt']);
        self::assertNull($items[0]['companyId']);
        self::assertIsString($items[0]['id']);
        self::assertIsString($items[0]['createdAt']);
    }

    public function testTheListIsOnlyTheSignedInUsersNewestFirst(): void
    {
        $other = $this->createUser('other@twes.local', 'password-1234');
        $this->item($this->owner, 'membership.added', '2026-09-13 09:00:00');
        $newer = $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:30:00', $this->company);
        $this->item($other, 'membership.added', '2026-09-13 10:00:00');

        $this->login('owner@twes.local', 'password-1234');
        $this->getJson('/api/me/notifications');

        $items = $this->arrayAt($this->json(), 'items');
        self::assertCount(2, $items);
        self::assertIsArray($items[0]);
        self::assertSame($newer->getId()->toRfc4122(), $items[0]['id']);
        self::assertSame($this->company->getId()->toRfc4122(), $items[0]['companyId']);
    }

    public function testMarkingOneReadStampsIt(): void
    {
        $item = $this->item($this->owner, 'membership.added', '2026-09-13 09:00:00');
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/me/notifications/'.$item->getId()->toRfc4122().'/read', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson('/api/me/notifications');
        $body = $this->json();
        self::assertSame(0, $body['unread']);
        $items = $this->arrayAt($body, 'items');
        self::assertIsArray($items[0]);
        self::assertIsString($items[0]['readAt']);
    }

    public function testSomeoneElsesNotificationIsNotFoundAndStaysUnread(): void
    {
        $other = $this->createUser('other@twes.local', 'password-1234');
        $theirs = $this->item($other, 'membership.added', '2026-09-13 09:00:00');
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/me/notifications/'.$theirs->getId()->toRfc4122().'/read', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $reread = $this->em()->find(InboxItem::class, $theirs->getId());
        self::assertNotNull($reread);
        self::assertFalse($reread->isRead());
    }

    public function testAnIdThatIsNotAUuidIsNotFound(): void
    {
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/me/notifications/not-a-uuid/read', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMarkingAllReadClearsTheCount(): void
    {
        $this->item($this->owner, 'membership.added', '2026-09-13 09:00:00');
        $this->item($this->owner, 'membership.added', '2026-09-13 09:10:00');
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/me/notifications/read-all', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson('/api/me/notifications');
        self::assertSame(0, $this->json()['unread']);
    }

    public function testTheUnreadAreCountedPerCompanyForTheSignedInUserOnly(): void
    {
        $globex = $this->createCompany('Globex');
        $this->addMembership($this->owner, $globex);
        // A company the person has left is no longer in the switcher, so its notifications are not counted for it
        // (audit 2026-10-06, C-F8).
        $initech = $this->createCompany('Initech');
        $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:35:00', $initech);
        $other = $this->createUser('other@twes.local', 'password-1234');
        $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:00:00', $this->company);
        $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:10:00', $this->company);
        $read = $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:20:00', $this->company);
        $read->markRead(new \DateTimeImmutable('2026-09-13 10:00:00'));
        $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:30:00', $globex);
        $this->item($this->owner, 'membership.added', '2026-09-13 09:40:00');
        $this->item($other, 'invitation.accepted', '2026-09-13 09:50:00', $this->company);
        $this->em()->flush();
        $this->login('owner@twes.local', 'password-1234');
        // The bell counts a company once this sign-in has proved itself for it.
        $this->proveCompany($globex, 'password-1234');

        $this->getJson('/api/me/notifications/unread-by-company');

        self::assertResponseIsSuccessful();
        $counts = $this->json()['counts'];
        self::assertIsArray($counts);
        ksort($counts);
        $expected = [$this->company->getId()->toRfc4122() => 2, $globex->getId()->toRfc4122() => 1];
        ksort($expected);
        self::assertSame($expected, $counts, 'a JSON object has no order, so the keys are sorted before comparing');
    }

    /**
     * Removal ends access to a company, its notifications included: a member removed from one no longer lists, counts,
     * opens or marks what it told them, while their own account's notices and another company's stay (security map
     * access F1).
     */
    public function testAMemberRemovedFromACompanyNoLongerListsItsNotifications(): void
    {
        $globex = $this->createCompany('Globex');
        $this->addMembership($this->owner, $globex);
        $own = $this->item($this->owner, 'membership.added', '2026-09-13 09:00:00');
        $acme = $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:10:00', $this->company);
        $left = $this->item($this->owner, 'invitation.accepted', '2026-09-13 09:20:00', $globex);
        $this->login('owner@twes.local', 'password-1234');
        $this->proveCompany($globex, 'password-1234');
        $this->getJson('/api/me/notifications');
        self::assertSame(3, $this->json()['unread'], 'all three while a member of both');

        $this->em()->getConnection()->executeStatement('DELETE FROM membership WHERE user_id = ? AND company_id = ?', [$this->owner->getId()->toRfc4122(), $globex->getId()->toRfc4122()]);

        $this->getJson('/api/me/notifications');
        self::assertResponseIsSuccessful();
        $body = $this->json();
        $listed = array_map(static fn (mixed $item): mixed => \is_array($item) ? $item['id'] : null, $this->arrayAt($body, 'items'));
        self::assertSame([$acme->getId()->toRfc4122(), $own->getId()->toRfc4122()], $listed);
        self::assertSame(2, $body['unread']);

        $this->postJson('/api/me/notifications/'.$left->getId()->toRfc4122().'/read', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postJson('/api/me/notifications/read-all', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->em()->clear();
        self::assertFalse($this->em()->find(InboxItem::class, $left->getId())?->isRead() ?? true, 'the left company’s item is not touched');
        self::assertTrue($this->em()->find(InboxItem::class, $acme->getId())?->isRead() ?? false);
    }

    public function testMarkingWithoutTheCsrfHeaderIsRefused(): void
    {
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/me/notifications/read-all', null, withCsrf: false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNobodyReadsTheCentreSignedOut(): void
    {
        $this->getJson('/api/me/notifications');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheRealtimeTokenListsTheUsersAndTheWorkingCompanysChannelsSignedWithTheConfiguredKey(): void
    {
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson('/api/me/realtime-token');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        [$header, $payload, $signature] = explode('.', $this->stringAt($body, 'token'));
        $key = static::getContainer()->getParameter('app.realtime.token_key');
        self::assertSame(HmacJwt::signature($header.'.'.$payload, $key), $signature);
        $claims = json_decode(HmacJwt::base64UrlDecode($payload), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        // The session's own subject, so its sign-out or its end closes its connections and no other's.
        $session = $this->em()->getConnection()->fetchOne('SELECT id FROM user_session WHERE user_id = ?', [$this->owner->getId()->toRfc4122()]);
        self::assertIsString($session);
        self::assertSame($this->owner->getId()->toRfc4122().'.'.$session, $claims['sub']);
        self::assertSame(['user:'.$this->owner->getId()->toRfc4122(), 'company:'.$this->company->getId()->toRfc4122()], $claims['channels']);
        self::assertIsString($body['expiresAt']);
    }

    public function testAnOperatorAlsoHearsThePlatformAndAMemberDoesNot(): void
    {
        // What changes about a company that /platform shows (a grant, an approval, an owner joining) reaches an operator
        // who works in no company (docs/SPEC.md § 7, support access as a bug).
        $operator = $this->createUser('operator@twes.local', 'password-1234', operator: true);
        $this->login('operator@twes.local', 'password-1234');

        self::assertSame(['user:'.$operator->getId()->toRfc4122(), 'platform'], $this->realtimeChannels());

        $this->client->getCookieJar()->clear();
        $this->login('owner@twes.local', 'password-1234');
        self::assertNotContains('platform', $this->realtimeChannels());
    }

    public function testSomeoneRemovedFromTheCompanyNoLongerHearsIt(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->em()->getConnection()->executeStatement('DELETE FROM membership WHERE user_id = ? AND company_id = ?', [$this->owner->getId()->toRfc4122(), $this->company->getId()->toRfc4122()]);

        self::assertSame(['user:'.$this->owner->getId()->toRfc4122()], $this->realtimeChannels());
    }

    public function testAMemberOfASuspendedCompanyNoLongerHearsIt(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->em()->getConnection()->executeStatement("UPDATE company SET status = 'suspended' WHERE id = ?", [$this->company->getId()->toRfc4122()]);

        self::assertSame(['user:'.$this->owner->getId()->toRfc4122()], $this->realtimeChannels());
    }

    /** Read in the shell until its retention ends, a closed company's members hear its owner reopen it elsewhere. */
    public function testAMemberOfACompanyItsOwnerClosedStillHearsIt(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->em()->getConnection()->executeStatement("UPDATE company SET status = 'closed' WHERE id = ?", [$this->company->getId()->toRfc4122()]);

        self::assertSame(['user:'.$this->owner->getId()->toRfc4122(), 'company:'.$this->company->getId()->toRfc4122()], $this->realtimeChannels());
    }

    public function testNobodyGetsARealtimeTokenSignedOut(): void
    {
        $this->getJson('/api/me/realtime-token');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return list<mixed> the channels the token issued now lists */
    private function realtimeChannels(): array
    {
        $this->getJson('/api/me/realtime-token');
        self::assertResponseIsSuccessful();
        [, $payload] = explode('.', $this->stringAt($this->json(), 'token'));
        $claims = json_decode(HmacJwt::base64UrlDecode($payload), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsList($claims['channels']);

        return $claims['channels'];
    }

    private function item(User $recipient, string $type, string $at, ?Company $company = null): InboxItem
    {
        $item = new InboxItem($recipient, $company, $type, [], new \DateTimeImmutable($at));
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }
}
