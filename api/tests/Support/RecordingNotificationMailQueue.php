<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Inbox\Application\NotificationMailQueue;
use Symfony\Component\Uid\Uuid;

/** Keeps what would have been handed to the worker, so a test reads which mails a notification queued. */
final class RecordingNotificationMailQueue implements NotificationMailQueue
{
    /** @var list<array{string, ?string, string, array<string, scalar|null>}> user, company, type, payload */
    public array $queued = [];

    public function queue(Uuid $userId, ?Uuid $companyId, string $type, array $payload): void
    {
        $this->queued[] = [$userId->toRfc4122(), $companyId?->toRfc4122(), $type, $payload];
    }
}
