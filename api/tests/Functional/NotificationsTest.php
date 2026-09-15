<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Inbox\Domain\InboxItem;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
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
        self::assertSame($this->owner->getId()->toRfc4122(), $claims['sub']);
        self::assertSame(['user:'.$this->owner->getId()->toRfc4122(), 'company:'.$this->company->getId()->toRfc4122()], $claims['channels']);
        self::assertIsString($body['expiresAt']);
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
