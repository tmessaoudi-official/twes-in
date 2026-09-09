<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifications;

use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use Psr\Log\LoggerInterface;

/**
 * The G1b adapter: it records what would have been delivered. The wire is Centrifugo and it lands at G2
 * together with the notification centre that displays it (docs/SPEC.md § 7, 2026-09-09). Nothing in the
 * application layer changes when it does; only this class is replaced.
 */
final readonly class LoggingNotifications implements Notifications
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function publish(Notification $notification): void
    {
        $this->logger->info('notification {type} for {channel}', [
            'type' => $notification->type,
            'channel' => $notification->channel,
            'payload' => $notification->payload,
        ]);
    }
}
