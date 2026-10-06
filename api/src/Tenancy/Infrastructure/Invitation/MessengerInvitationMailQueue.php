<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Invitation;

use App\Tenancy\Application\Invitation\InvitationMailQueue;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The Doctrine transport stores the message in the transaction it is dispatched in, beside the invitation. Aliased by
 * name, since the fixtures' decorator is a second implementation of the port.
 */
#[AsAlias(InvitationMailQueue::class)]
final readonly class MessengerInvitationMailQueue implements InvitationMailQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(Uuid $invitationId): void
    {
        $this->bus->dispatch(new InvitationToMail($invitationId->toRfc4122()));
    }
}
