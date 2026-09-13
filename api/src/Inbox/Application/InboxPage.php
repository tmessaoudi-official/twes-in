<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use App\Inbox\Domain\InboxItem;

final readonly class InboxPage
{
    /** @param list<InboxItem> $items newest first */
    public function __construct(
        public array $items,
        public int $unread,
    ) {
    }
}
