<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Application\Transactions;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The real transaction and the real stage together (docs/SPEC.md § 7, 2026-09-17): a unit of work that fails says
 * nothing, and the units after it in the same worker still say theirs. A worker process serves many requests, so a
 * failure that left the stage counting a unit still open would silence every later change until it restarts.
 */
final class LiveChangesAfterCommitTest extends KernelTestCase
{
    public function testAFailedUnitSaysNothingAndTheNextUnitsStillSpeak(): void
    {
        self::bootKernel();
        $publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $publisher);
        $transactions = static::getContainer()->get(Transactions::class);
        $changes = static::getContainer()->get(LiveChanges::class);
        $company = Uuid::v7();

        try {
            $transactions->run(static function () use ($changes, $company): never {
                $changes->stage(new LiveChange('customer', Uuid::v7(), 'customer.created', null, $company));
                throw new \DomainException('refused');
            });
        } catch (\DomainException) {
            // the refusal is the point
        }
        self::assertSame([], $publisher->pushed);

        foreach (['vendor.created', 'vendor.revised'] as $action) {
            $transactions->run(static function () use ($changes, $company, $action): void {
                $changes->stage(new LiveChange('vendor', Uuid::v7(), $action, null, $company));
            });
        }
        self::assertSame(['vendor.created', 'vendor.revised'], array_column(array_column($publisher->pushed, 'data'), 'action'));
    }

    public function testNestedUnitsSpeakOnceTheOutermostCommits(): void
    {
        self::bootKernel();
        $publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $publisher);
        $transactions = static::getContainer()->get(Transactions::class);
        $changes = static::getContainer()->get(LiveChanges::class);

        $transactions->run(static function () use ($transactions, $changes, $publisher): void {
            $transactions->run(static fn () => $changes->stage(new LiveChange('invoice', Uuid::v7(), 'invoice.issued', null, Uuid::v7())));
            self::assertSame([], $publisher->pushed, 'the inner unit is not the commit');
        });

        self::assertCount(1, $publisher->pushed);
    }
}
