<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Erasure\Domain;

use App\Erasure\Domain\DataErasure;
use App\Erasure\Domain\ErasureNoLongerPending;
use App\Erasure\Domain\ErasureState;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** An erasure may be undone until its end, not one second after; then, or once undone, it is over for good. */
final class DataErasureTest extends TestCase
{
    private const string AT = '2026-10-10 12:00:00';

    public function testItWaitsTwentyFourHoursAndIsUndoneUntilThen(): void
    {
        $erasure = $this->erasure();

        self::assertSame(ErasureState::Pending, $erasure->getState());
        self::assertEquals(new \DateTimeImmutable('2026-10-11 12:00:00'), $erasure->getEffectiveAt());
        self::assertTrue($erasure->isUndoableAt(new \DateTimeImmutable('2026-10-11 11:59:59')));

        $erasure->undo(new \DateTimeImmutable('2026-10-11 11:59:59'));

        self::assertSame(ErasureState::Undone, $erasure->getState());
        $this->assertRefused(static fn () => $erasure->undo(new \DateTimeImmutable('2026-10-11 11:59:59')));
        self::assertFalse($erasure->finishIfDue(new \DateTimeImmutable('2026-10-12 00:00:00')), 'an undone erasure has nothing left to end');
    }

    public function testAtItsEndItIsNoLongerUndoneEvenBeforeAnythingEndsIt(): void
    {
        $erasure = $this->erasure();

        self::assertFalse($erasure->isUndoableAt(new \DateTimeImmutable('2026-10-11 12:00:00')));
        $this->assertRefused(static fn () => $erasure->undo(new \DateTimeImmutable('2026-10-11 12:00:00')));
        self::assertSame(ErasureState::Pending, $erasure->getState());
    }

    public function testItEndsOnlyOnceItsTimeHasCome(): void
    {
        $erasure = $this->erasure();

        self::assertFalse($erasure->finishIfDue(new \DateTimeImmutable('2026-10-11 11:59:59')));
        self::assertSame(ErasureState::Pending, $erasure->getState());
        self::assertTrue($erasure->finishIfDue(new \DateTimeImmutable('2026-10-11 12:00:00')));
        self::assertSame(ErasureState::Final, $erasure->getState());
        self::assertFalse($erasure->finishIfDue(new \DateTimeImmutable('2026-10-11 12:15:00')), 'ended once');
    }

    public function testItErasesAtLeastOneKnownPartOnce(): void
    {
        foreach ([[], ['drafts', 'drafts']] as $parts) {
            try {
                new DataErasure(Uuid::v7(), new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), $parts, [], null, new \DateTimeImmutable(self::AT), new \DateInterval('PT24H'));
                self::fail('refused: '.json_encode($parts));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function erasure(): DataErasure
    {
        return new DataErasure(Uuid::v7(), new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), ['stock_map'], ['stock_map' => ['floors' => 1]], Uuid::v7(), new \DateTimeImmutable(self::AT), new \DateInterval('PT24H'));
    }

    private function assertRefused(callable $undo): void
    {
        try {
            $undo();
            self::fail('the undo was refused');
        } catch (ErasureNoLongerPending) {
            $this->addToAssertionCount(1);
        }
    }
}
