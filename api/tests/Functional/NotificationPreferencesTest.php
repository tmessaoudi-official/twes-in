<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Inbox\Domain\InboxItem;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mon compte › Notifications »: each person chooses, per kind and per company, whether the bell counts it. Every kind
 * starts with the bell on and its e-mail on; a muted kind is still listed in the centre but never counted.
 */
final class NotificationPreferencesTest extends ApiTestCase
{
    private const string PATH = '/api/me/notification-preferences';

    private Company $acme;
    private Company $globex;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->acme = $this->createCompany('Acme');
        $this->globex = $this->createCompany('Globex');
        $this->owner = $this->createUser('owner@twes.local', 'password-1234', $this->acme);
        $this->addMembership($this->owner, $this->globex, permissions: ['invoice.read']);
    }

    public function testEveryKindTheRoleReceivesStartsWithTheBellAndTheMailOnInEachCompany(): void
    {
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson(self::PATH);

        self::assertResponseIsSuccessful();
        $rows = $this->rows();
        $acme = $this->acme->getId()->toRfc4122();
        $globex = $this->globex->getId()->toRfc4122();
        self::assertSame(['bell' => true, 'email' => true], $rows["$acme stock.low"] ?? null);
        self::assertSame(['bell' => true, 'email' => true], $rows["$acme subscription.payment_decided"] ?? null, 'the owner is told how a payment was decided');
        self::assertArrayHasKey("$globex invitation.accepted", $rows);
        self::assertArrayNotHasKey("$globex stock.low", $rows, 'a role without stock.write is never told of stock, so is not asked');
        self::assertArrayNotHasKey("$globex subscription.payment_decided", $rows, 'only an owner is told how a payment was decided');
        self::assertSame(['bell' => true, 'email' => true], $rows[' invitation.received'] ?? null, 'an invitation to a company is about the account');
        self::assertArrayNotHasKey(' subscription.payment_declared', $rows, 'what operators are told is not offered to a member');
    }

    public function testAMutedKindIsListedButNotCountedAndOnlyInThatCompany(): void
    {
        $this->item($this->acme, 'stock.low');
        $this->item($this->acme, 'invitation.accepted');
        $this->item($this->globex, 'stock.low');
        $this->login('owner@twes.local', 'password-1234');

        $this->sendJson('PUT', self::PATH, ['companyId' => $this->acme->getId()->toRfc4122(), 'type' => 'stock.low', 'bell' => false, 'email' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson(self::PATH);
        self::assertSame(['bell' => false, 'email' => true], $this->rows()[$this->acme->getId()->toRfc4122().' stock.low']);
        $this->getJson('/api/me/notifications');
        self::assertSame(2, $this->json()['unread'], 'Acme\'s stock alert is muted, Globex\'s is not');
        self::assertCount(3, $this->arrayAt($this->json(), 'items'), 'a muted kind is still in the centre');
        $this->getJson('/api/me/notifications/unread-by-company');
        self::assertEquals([$this->acme->getId()->toRfc4122() => 1, $this->globex->getId()->toRfc4122() => 1], $this->json()['counts']);

        $this->sendJson('PUT', self::PATH, ['companyId' => $this->acme->getId()->toRfc4122(), 'type' => 'stock.low', 'bell' => true, 'email' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson('/api/me/notifications');
        self::assertSame(3, $this->json()['unread'], 'turned back on, what it said is counted again');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_preference'), 'a choice is one row, changed in place');
    }

    public function testAPersonalKindIsChosenWithoutACompany(): void
    {
        $this->login('owner@twes.local', 'password-1234');

        $this->sendJson('PUT', self::PATH, ['companyId' => null, 'type' => 'invitation.received', 'bell' => false, 'email' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson(self::PATH);
        self::assertSame(['bell' => false, 'email' => false], $this->rows()[' invitation.received']);
    }

    public function testOnlyAKindOfferedThereIsChosen(): void
    {
        $stranger = $this->createCompany('Initech');
        $this->login('owner@twes.local', 'password-1234');

        foreach ([
            'another company' => ['companyId' => $stranger->getId()->toRfc4122(), 'type' => 'invitation.accepted'],
            'a kind the role never receives' => ['companyId' => $this->globex->getId()->toRfc4122(), 'type' => 'stock.low'],
            'a kind nobody declared' => ['companyId' => $this->acme->getId()->toRfc4122(), 'type' => 'stock.flood'],
            'a company kind without its company' => ['companyId' => null, 'type' => 'stock.low'],
        ] as $case => $choice) {
            $this->sendJson('PUT', self::PATH, [...$choice, 'bell' => false, 'email' => true]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $case);
        }
        foreach ([
            'a switch that is not a yes or a no' => ['bell' => 'off', 'email' => true],
            'a switch left out' => ['bell' => false],
        ] as $case => $switches) {
            $this->sendJson('PUT', self::PATH, ['companyId' => $this->acme->getId()->toRfc4122(), 'type' => 'stock.low', ...$switches]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
        }
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_preference'));
    }

    public function testNobodyChoosesSignedOutOrWithoutTheCsrfHeader(): void
    {
        $this->getJson(self::PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login('owner@twes.local', 'password-1234');
        $this->sendJson('PUT', self::PATH, ['companyId' => null, 'type' => 'invitation.received', 'bell' => false, 'email' => true], withCsrf: false);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @return array<string, array{bell: mixed, email: mixed}> by "<company id or nothing> <type>" */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->arrayAt($this->json(), 'preferences') as $row) {
            self::assertIsArray($row);
            $company = $row['companyId'] ?? '';
            self::assertIsString($company);
            self::assertIsString($row['type']);
            $rows[$company.' '.$row['type']] = ['bell' => $row['bell'], 'email' => $row['email']];
        }

        return $rows;
    }

    private function item(Company $company, string $type): void
    {
        $this->em()->persist(new InboxItem($this->owner, $company, $type, [], new \DateTimeImmutable('2026-10-08 09:00:00')));
        $this->em()->flush();
    }
}
