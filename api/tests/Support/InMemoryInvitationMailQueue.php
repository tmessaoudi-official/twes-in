<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Application\Invitation\InvitationMailQueue;
use App\Tenancy\Application\Invitation\MailInvitation;
use Symfony\Component\Uid\Uuid;

/** Remembers what was queued; given a worker, it also hands each one over at once, as a worker that never lags would. */
final class InMemoryInvitationMailQueue implements InvitationMailQueue
{
    /** @var list<Uuid> */
    public array $queued = [];

    public ?MailInvitation $worker = null;

    public function queue(Uuid $invitationId): void
    {
        $this->queued[] = $invitationId;
        $this->worker?->handle($invitationId);
    }
}
