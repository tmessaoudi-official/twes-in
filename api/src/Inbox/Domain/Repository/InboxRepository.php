<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Domain\Repository;

use App\Inbox\Domain\Model\InboxItem;
use Symfony\Component\Uid\Uuid;

/**
 * What a recipient reads, counts, opens or marks is their own notifications of no company and those of the companies
 * they still belong to: a notification of a company they were removed from is theirs no longer.
 */
interface InboxRepository
{
    /** Writes every item together: one notification fanned out to a company is one write. */
    public function add(InboxItem ...$items): void;

    /** @return list<InboxItem> at most $limit of them, newest first */
    public function latestFor(Uuid $recipientId, int $limit): array;

    public function unreadCountFor(Uuid $recipientId): int;

    /**
     * The recipient's unread notifications by the company they concern, those of no company and of a company they no longer
     * belong to left out.
     *
     * @return array<string, int> company id => count, only the companies with something unread
     */
    public function unreadByCompanyFor(Uuid $recipientId): array;

    /** The item only when it belongs to that recipient: nobody reads or marks another person's notifications. */
    public function ofRecipient(Uuid $recipientId, Uuid $itemId): ?InboxItem;

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at): void;

    public function save(InboxItem $item): void;
}
