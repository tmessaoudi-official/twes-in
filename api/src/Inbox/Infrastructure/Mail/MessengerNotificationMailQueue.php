<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use App\Inbox\Application\NotificationMailQueue;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The Doctrine transport stores the message in the transaction that wrote the notification, so a change rolled back
 * mails nothing.
 */
#[AsAlias(NotificationMailQueue::class)]
final readonly class MessengerNotificationMailQueue implements NotificationMailQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(Uuid $userId, ?Uuid $companyId, string $type, array $payload): void
    {
        $this->bus->dispatch(new NotificationToMail($userId->toRfc4122(), $companyId?->toRfc4122(), $type, $payload));
    }
}
