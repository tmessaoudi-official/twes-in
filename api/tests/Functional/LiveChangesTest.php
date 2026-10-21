<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Application\RealtimePublisher;
use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Component\HttpFoundation\Response;

/**
 * A change made through the API reaches the company's open screens once its transaction has committed, naming who
 * made it and from which tab (docs/SPEC.md § 7, 2026-09-17); a refused change says nothing.
 */
final class LiveChangesTest extends ApiTestCase
{
    private Company $company;
    private RecordingRealtimePublisher $publisher;
    private string $salesId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $this->publisher);
        $this->company = $this->createCompany('Acme');
        $this->salesId = $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'customer.write'], 'member')->getId()->toRfc4122();
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testACreatedGroupIsSaidToTheCompanyAfterItsRowIsStored(): void
    {
        $stored = null;
        $this->publisher->onPush = function () use (&$stored): void {
            $stored = $this->em()->getConnection()->fetchOne("SELECT count(*) FROM customer_group WHERE name = 'Grossistes'");
        };

        $this->postJson($this->path(), ['name' => 'Grossistes'], server: ['HTTP_X_TAB' => 'tab-1']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $changes = array_values(array_filter($this->publisher->pushed, static fn (array $push) => 'changed' === ($push['data']['type'] ?? null)));
        self::assertCount(1, $changes);
        self::assertSame('company:'.$this->company->getId()->toRfc4122(), $changes[0]['channel']);
        self::assertSame('customer_group', $changes[0]['data']['kind']);
        self::assertSame($this->json()['id'], $changes[0]['data']['id']);
        self::assertSame('tab-1', $changes[0]['data']['origin']);
        self::assertSame(['id' => $this->salesId, 'name' => 'Sales'], $changes[0]['data']['actor']);
        self::assertSame(1, is_numeric($stored) ? (int) $stored : null, 'the row was stored when the change was said');
    }

    /**
     * The connection says a change to every tab but the one that made it, so the answer names it to that tab, whose
     * other panels (a count, the bell, another list) hear it as they would hear another tab's.
     */
    public function testTheAnswerNamesWhatTheRequestChangedToTheTabThatMadeIt(): void
    {
        $this->postJson($this->path(), ['name' => 'Grossistes'], server: ['HTTP_X_TAB' => 'tab-1']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $header = $this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER);
        self::assertNotNull($header);
        self::assertSame([['kind' => 'customer_group', 'id' => $this->json()['id'], 'action' => 'customer_group.created']], json_decode($header, true));
    }

    public function testARequestNamingNoTabIsAnsweredWithoutTheList(): void
    {
        $this->postJson($this->path(), ['name' => 'Grossistes']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertFalse($this->client->getResponse()->headers->has(LiveChangesAnswer::HEADER));
    }

    public function testARefusedChangeSaysNothing(): void
    {
        $this->postJson($this->path(), ['name' => '  '], server: ['HTTP_X_TAB' => 'tab-1']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->publisher->pushed);
        self::assertFalse($this->client->getResponse()->headers->has(LiveChangesAnswer::HEADER));
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/customer-groups';
    }
}
