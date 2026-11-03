<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Alerts;

use App\Module\Invoices\Application\Credit\CreditAlerts;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Infrastructure\Doctrine\StagedUntilCommit;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the credit alerts an issue decided until its outermost unit of work commits, then tells them; a unit rolled back
 * forgets its own. The invoice is already committed when they are told: an alert that cannot be written is logged, never
 * thrown at the person who issued it.
 */
final class StagedCreditAlerts implements CreditAlerts, StagedUntilCommit, ResetInterface
{
    /** @var list<list<Notification>> what each open level decided, the innermost last */
    private array $levels = [];

    public function __construct(private readonly Notifications $notifications, private readonly LoggerInterface $logger)
    {
    }

    public function onceCommitted(Notification ...$notifications): void
    {
        if ([] === $this->levels) {
            $this->tell(array_values($notifications));

            return;
        }
        $inner = \count($this->levels) - 1;
        $this->levels[$inner] = [...$this->levels[$inner], ...array_values($notifications)];
    }

    public function begin(): void
    {
        $this->levels[] = [];
    }

    public function commit(): void
    {
        $level = array_pop($this->levels) ?? [];
        if ([] === $this->levels) {
            $this->tell($level);

            return;
        }
        $outer = \count($this->levels) - 1;
        $this->levels[$outer] = [...$this->levels[$outer], ...$level];
    }

    public function rollBack(): void
    {
        array_pop($this->levels);
    }

    public function reset(): void
    {
        $this->levels = [];
    }

    /** @param list<Notification> $notifications */
    private function tell(array $notifications): void
    {
        foreach ($notifications as $notification) {
            try {
                $this->notifications->publish($notification);
            } catch (\Throwable $failure) {
                $this->logger->warning('A credit limit alert to {channel} was not told: {reason}', ['channel' => $notification->channel, 'reason' => $failure->getMessage(), 'exception' => $failure]);
            }
        }
    }
}
