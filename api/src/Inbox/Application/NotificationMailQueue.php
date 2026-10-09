<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Hands a notification's mail to the worker: the request that told it never waits for a mail server, and the worker
 * asks again, when it sends, whether the person still wants that kind mailed.
 */
interface NotificationMailQueue
{
    /** @param array<string, scalar|null> $payload */
    public function queue(Uuid $userId, ?Uuid $companyId, string $type, array $payload): void;
}
