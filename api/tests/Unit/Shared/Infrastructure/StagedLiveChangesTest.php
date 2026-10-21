<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Application\LiveChange;
use App\Shared\Infrastructure\Realtime\StagedLiveChanges;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\RecordingRealtimePublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * What changed reaches every open screen once, and only once the unit of work that changed it has committed
 * (docs/SPEC.md § 7, 2026-09-17): a screen reloading on the message must read the new row, and a refused change
 * must say nothing.
 */
#[CoversClass(StagedLiveChanges::class)]
final class StagedLiveChangesTest extends TestCase
{
    private RecordingRealtimePublisher $publisher;
    private InMemoryUsers $users;
    private RequestStack $requests;
    private StagedLiveChanges $changes;
    private User $nadia;
    private Uuid $company;

    protected function setUp(): void
    {
        $this->publisher = new RecordingRealtimePublisher();
        $this->users = new InMemoryUsers();
        $this->requests = new RequestStack();
        $this->changes = new StagedLiveChanges($this->publisher, $this->users, $this->requests);
        $this->nadia = new User(Email::fromString('nadia@example.test'), 'Nadia Belkacem', 'fr');
        $this->users->save($this->nadia);
        $this->company = Uuid::v7();
    }

    public function testAChangeWaitsForTheUnitOfWorkToCommitThenReachesTheCompanysChannel(): void
    {
        $customer = Uuid::v7();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('customer', $customer, 'customer.revised', $this->nadia->getId(), $this->company));
        self::assertSame([], $this->publisher->pushed, 'nothing is said before the commit');

        $this->changes->commit();

        self::assertSame([[
            'channel' => 'company:'.$this->company->toRfc4122(),
            'data' => [
                'type' => 'changed',
                'kind' => 'customer',
                'id' => $customer->toRfc4122(),
                'action' => 'customer.revised',
                'actor' => ['id' => $this->nadia->getId()->toRfc4122(), 'name' => 'Nadia Belkacem'],
                'origin' => null,
            ],
        ]], $this->publisher->pushed);
    }

    public function testANestedUnitOfWorkSaysNothingUntilTheOutermostCommits(): void
    {
        $this->changes->begin();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('invoice', Uuid::v7(), 'invoice.issued', null, $this->company));
        $this->changes->commit();
        self::assertSame([], $this->publisher->pushed);

        $this->changes->commit();
        self::assertCount(1, $this->publisher->pushed);
    }

    public function testARolledBackUnitOfWorkSaysNothingAndTheNextOneStartsClean(): void
    {
        $this->changes->begin();
        $this->changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.created', null, $this->company));
        $this->changes->rollBack();
        $this->changes->begin();
        $this->changes->commit();

        self::assertSame([], $this->publisher->pushed);
    }

    public function testAChangeStagedOutsideAUnitOfWorkIsNotADataChangeAndIsDropped(): void
    {
        $this->changes->stage(new LiveChange('user', $this->nadia->getId(), 'login.succeeded', $this->nadia->getId(), $this->company));
        $this->changes->begin();
        $this->changes->commit();

        self::assertSame([], $this->publisher->pushed);
    }

    public function testTheSameChangeTwiceInOneUnitIsSaidOnceAndDistinctChangesInOrder(): void
    {
        $invoice = Uuid::v7();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('invoice', $invoice, 'invoice.payment_recorded', null, $this->company));
        $this->changes->stage(new LiveChange('invoice', $invoice, 'invoice.payment_recorded', null, $this->company));
        $this->changes->stage(new LiveChange('invoice', $invoice, 'invoice.revised', null, $this->company));
        $this->changes->commit();

        self::assertSame(['invoice.payment_recorded', 'invoice.revised'], array_map(static fn (array $push) => $push['data']['action'], $this->publisher->pushed));
    }

    /**
     * A file of a thousand rows, or any other write of many records, says so once per kind and action: a screen reads
     * its list again once, not a thousand times, and the actor's name is read once.
     */
    public function testManyChangesOfOneKindAreSaidOnceAsABulkChange(): void
    {
        $this->changes->begin();
        for ($i = 0; $i < 11; ++$i) {
            $this->changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.created', $this->nadia->getId(), $this->company));
        }
        $this->changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.revised', $this->nadia->getId(), $this->company));
        $this->changes->commit();

        self::assertSame([
            ['kind' => 'customer', 'id' => null, 'action' => 'customer.created', 'bulk' => true, 'count' => 11],
            ['kind' => 'customer', 'id' => 'one', 'action' => 'customer.revised', 'bulk' => null, 'count' => null],
        ], array_map(static fn (array $push): array => ['kind' => $push['data']['kind'], 'id' => null === $push['data']['id'] ? null : 'one', 'action' => $push['data']['action'], 'bulk' => $push['data']['bulk'] ?? null, 'count' => $push['data']['count'] ?? null], $this->publisher->pushed));
        self::assertSame(['id' => $this->nadia->getId()->toRfc4122(), 'name' => 'Nadia Belkacem'], $this->publisher->pushed[0]['data']['actor']);
        self::assertSame(1, $this->users->reads, 'the actor is read once for the commit');
    }

    public function testTenChangesAreStillSaidOneByOneAndADeletionIsNeverFolded(): void
    {
        $this->changes->begin();
        for ($i = 0; $i < 10; ++$i) {
            $this->changes->stage(new LiveChange('vendor', Uuid::v7(), 'vendor.created', null, $this->company));
        }
        for ($i = 0; $i < 12; ++$i) {
            $this->changes->stage(new LiveChange('vendor', Uuid::v7(), 'vendor.deleted', null, $this->company));
        }
        $this->changes->commit();

        self::assertCount(22, $this->publisher->pushed, 'an open editor is told its own record is gone');
        self::assertSame([], array_filter(array_column(array_column($this->publisher->pushed, 'data'), 'bulk')));
    }

    public function testWhatBelongsToNoCompanyReachesItsActorsOwnChannelAndWhatHasNeitherReachesNobody(): void
    {
        $this->changes->begin();
        $this->changes->stage(new LiveChange('setting', Uuid::v7(), 'setting.changed', $this->nadia->getId(), null));
        $this->changes->stage(new LiveChange('setting', Uuid::v7(), 'setting.changed', null, null));
        $this->changes->commit();

        self::assertSame(['user:'.$this->nadia->getId()->toRfc4122()], array_column($this->publisher->pushed, 'channel'));
    }

    public function testTheTabThatMadeTheChangeIsNamedSoItCanIgnoreItsOwnEchoAndAnUnreadableTabIsNot(): void
    {
        foreach (['0f8c2b1e-tab-7' => '0f8c2b1e-tab-7', str_repeat('a', 65) => null, 'tab id; drop' => null] as $header => $origin) {
            $request = new Request();
            $request->headers->set('X-Tab', $header);
            $this->requests->push($request);
            $this->changes->begin();
            $this->changes->stage(new LiveChange('vendor', Uuid::v7(), 'vendor.created', null, $this->company));
            $this->changes->commit();
            $this->requests->pop();

            $pushed = array_pop($this->publisher->pushed);
            self::assertNotNull($pushed, $header);
            self::assertSame($origin, $pushed['data']['origin'], $header);
        }
    }

    public function testAnActorWhoCannotBeFoundIsNamedByIdOnly(): void
    {
        $stranger = Uuid::v7();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('product', Uuid::v7(), 'product.created', $stranger, $this->company));
        $this->changes->commit();

        self::assertSame(['id' => $stranger->toRfc4122(), 'name' => null], $this->publisher->pushed[0]['data']['actor']);
    }

    /**
     * The tab that made a request hears its own changes in the answer, as every other tab hears them through the
     * connection: what the request said is kept for that answer, a later unit rolled back takes none of it away, and
     * the next request starts with nothing.
     */
    public function testWhatARequestSaidIsKeptForItsAnswerUntilTheNextRequest(): void
    {
        $request = new Request();
        $request->headers->set('X-Tab', 'tab-1');
        $this->requests->push($request);
        $customer = Uuid::v7();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('customer', $customer, 'customer.revised', $this->nadia->getId(), $this->company));
        $this->changes->commit();
        $this->changes->begin();
        for ($i = 0; $i < 11; ++$i) {
            $this->changes->stage(new LiveChange('product', Uuid::v7(), 'product.created', $this->nadia->getId(), $this->company));
        }
        $this->changes->commit();
        $this->changes->begin();
        $this->changes->stage(new LiveChange('vendor', Uuid::v7(), 'vendor.created', null, $this->company));
        $this->changes->rollBack();

        self::assertSame([
            ['kind' => 'customer', 'id' => $customer->toRfc4122(), 'action' => 'customer.revised'],
            ['kind' => 'product', 'id' => null, 'action' => 'product.created', 'bulk' => true, 'count' => 11],
        ], $this->changes->saidHere());

        $this->changes->reset();
        self::assertSame([], $this->changes->saidHere());
    }

    public function testARequestNamingNoTabKeepsNothingForItsAnswer(): void
    {
        $this->requests->push(new Request());
        $this->changes->begin();
        $this->changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.revised', null, $this->company));
        $this->changes->commit();

        self::assertCount(1, $this->publisher->pushed);
        self::assertSame([], $this->changes->saidHere(), 'only a tab of the app reads its own changes from the answer');
    }

    public function testResetBetweenRequestsForgetsWhatAnAbandonedUnitStaged(): void
    {
        $this->changes->begin();
        $this->changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.created', null, $this->company));
        $this->changes->reset();
        $this->changes->begin();
        $this->changes->commit();

        self::assertSame([], $this->publisher->pushed);
    }
}
