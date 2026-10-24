<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Queues the notice on the Doctrine transport, which writes it in the change's own transaction (the outbox). */
final readonly class MessengerSecurityNotices implements SecurityNotices
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function tell(Uuid $userId, SecurityChange $change, ?string $passkeyName = null): void
    {
        $this->bus->dispatch(new SecurityNoticeToMail($userId->toRfc4122(), $change->value, $passkeyName));
    }
}
