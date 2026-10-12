<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\Archiving;
use App\Shared\Infrastructure\Health\DeepHealth;
use App\Shared\Infrastructure\Health\HealthReadings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeepHealthTest extends TestCase
{
    private const int OLDEST_WAITING = 600;
    private const float DISK_FREE = 0.2;

    public function testAQuietHealthyServerIsOk(): void
    {
        $verdict = self::judge(new HealthReadings(0, null, 0.55, Archiving::Working));

        self::assertTrue($verdict['ok']);
        self::assertSame([], $verdict['failing']);
        self::assertSame(['failedMessages' => 0, 'oldestWaitingSeconds' => null, 'diskFree' => 0.55, 'archiving' => 'working'], $verdict['readings']);
    }

    /** @return iterable<string, array{HealthReadings, list<string>}> */
    public static function failures(): iterable
    {
        yield 'a message that failed for good' => [new HealthReadings(1, null, 0.5, Archiving::Working), ['failedMessages']];
        yield 'a message waiting past the limit: no worker reads the queue' => [new HealthReadings(0, 601, 0.5, Archiving::Working), ['oldestWaiting']];
        yield 'the disk past its floor' => [new HealthReadings(0, null, 0.19, Archiving::Working), ['diskFree']];
        yield 'archiving that fails' => [new HealthReadings(0, null, 0.5, Archiving::Failing), ['archiving']];
        yield 'archiving switched off where backups run' => [new HealthReadings(0, null, 0.5, Archiving::Off), ['archiving']];
        yield 'nothing ever archived where backups run' => [new HealthReadings(0, null, 0.5, Archiving::NeverArchived), ['archiving']];
        yield 'everything at once, each named' => [new HealthReadings(2, 9000, 0.01, Archiving::Failing), ['failedMessages', 'oldestWaiting', 'diskFree', 'archiving']];
    }

    /** @param list<string> $failing */
    #[DataProvider('failures')]
    public function testEachFailureIsNamed(HealthReadings $readings, array $failing): void
    {
        $verdict = self::judge($readings);

        self::assertFalse($verdict['ok']);
        self::assertSame($failing, $verdict['failing']);
    }

    public function testTheLimitsThemselvesStillPass(): void
    {
        self::assertTrue(self::judge(new HealthReadings(0, 600, 0.2, Archiving::Working))['ok']);
    }

    /** A machine without the backup layer archives nothing, and is not failing for it. */
    public function testArchivingThatIsNotExpectedIsNotAFailure(): void
    {
        $verdict = self::judge(new HealthReadings(0, null, 0.5, Archiving::NotExpected));

        self::assertTrue($verdict['ok']);
        self::assertSame('not expected', $verdict['readings']['archiving']);
    }

    /** @return array{ok: bool, failing: list<string>, readings: array<string, mixed>} */
    private static function judge(HealthReadings $readings): array
    {
        return new DeepHealth(self::OLDEST_WAITING, self::DISK_FREE)->judge($readings);
    }
}
