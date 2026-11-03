<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Infrastructure;

use App\Module\Invoices\Infrastructure\Alerts\StagedCreditAlerts;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tests\Support\InMemoryNotifications;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/** A credit alert decided in an issue is told once its outermost unit commits, and never for a unit rolled back. */
final class StagedCreditAlertsTest extends TestCase
{
    public function testAnAlertIsToldOnlyOnceTheOutermostUnitCommits(): void
    {
        $told = new InMemoryNotifications();
        $alerts = new StagedCreditAlerts($told, new NullLogger());

        $alerts->begin();
        $alerts->begin();
        $alerts->onceCommitted(self::alert('a'));
        $alerts->commit();
        self::assertSame([], self::channels($told), 'the inner unit committed, the outer one has not');
        $alerts->commit();

        self::assertSame(['user:a'], self::channels($told));
    }

    public function testAnAlertOfAUnitRolledBackIsNeverTold(): void
    {
        $told = new InMemoryNotifications();
        $alerts = new StagedCreditAlerts($told, new NullLogger());

        $alerts->begin();
        $alerts->onceCommitted(self::alert('kept'));
        $alerts->begin();
        $alerts->onceCommitted(self::alert('undone'));
        $alerts->rollBack();
        $alerts->commit();
        $alerts->begin();
        $alerts->onceCommitted(self::alert('failed'));
        $alerts->rollBack();

        self::assertSame(['user:kept'], self::channels($told));
    }

    public function testAnAlertThatCannotBeWrittenIsLoggedAndTheOthersAreStillTold(): void
    {
        $told = new InMemoryNotifications();
        $failing = new class($told) implements Notifications {
            public function __construct(private readonly InMemoryNotifications $told)
            {
            }

            public function publish(Notification $notification): void
            {
                if ('user:broken' === $notification->channel) {
                    throw new \RuntimeException('inbox down');
                }
                $this->told->publish($notification);
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };
        $alerts = new StagedCreditAlerts($failing, $logger);

        $alerts->begin();
        $alerts->onceCommitted(self::alert('broken'), self::alert('fine'));
        $alerts->commit();

        self::assertSame(['user:fine'], self::channels($told));
        self::assertCount(1, $logger->warnings);
    }

    public function testAResetForgetsWhatAnUnfinishedRequestDecided(): void
    {
        $told = new InMemoryNotifications();
        $alerts = new StagedCreditAlerts($told, new NullLogger());

        $alerts->begin();
        $alerts->onceCommitted(self::alert('left'));
        $alerts->reset();
        $alerts->begin();
        $alerts->commit();

        self::assertSame([], self::channels($told));
    }

    /** @return list<string> */
    private static function channels(InMemoryNotifications $told): array
    {
        $channels = [];
        foreach ($told->published as $notification) {
            $channels[] = $notification->channel;
        }

        return $channels;
    }

    private static function alert(string $user): Notification
    {
        return new Notification('user:'.$user, 'invoice.credit_limit_passed', []);
    }
}
