<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Domain;

use Symfony\Component\Uid\Uuid;

interface InboxRepository
{
    /** Writes every item together: one notification fanned out to a company is one write. */
    public function add(InboxItem ...$items): void;

    /** @return list<InboxItem> at most $limit of them, newest first */
    public function latestFor(Uuid $recipientId, int $limit): array;

    public function unreadCountFor(Uuid $recipientId): int;

    /** The item only when it belongs to that recipient: nobody reads or marks another person's notifications. */
    public function ofRecipient(Uuid $recipientId, Uuid $itemId): ?InboxItem;

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at): void;

    public function save(InboxItem $item): void;
}
