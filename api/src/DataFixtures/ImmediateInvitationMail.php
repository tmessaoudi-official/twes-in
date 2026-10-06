<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Tenancy\Application\Invitation\InvitationMailQueue;
use App\Tenancy\Application\Invitation\MailInvitation;
use App\Tenancy\Infrastructure\Invitation\MessengerInvitationMailQueue;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Mails an invitation at once, inside the fixtures' own run, instead of queueing it for the worker.
 *
 * The demo fixtures accept each invitation straight after sending it, with the token `CapturingInvitationMailer` read
 * off the mail; queued, the worker would make that token in another process, and might make it after the fixtures had
 * already tried. Only inside `during()` does it mail at once; everywhere else, the app included, it queues as production
 * does. `#[When]` keeps it out of production entirely.
 */
#[When('dev')]
#[When('test')]
#[AsDecorator(MessengerInvitationMailQueue::class)]
final class ImmediateInvitationMail implements InvitationMailQueue, ResetInterface
{
    private bool $now = false;

    public function __construct(
        #[AutowireDecorated] private readonly InvitationMailQueue $inner,
        private readonly MailInvitation $mail,
    ) {
    }

    public function queue(Uuid $invitationId): void
    {
        if ($this->now) {
            $this->mail->handle($invitationId);

            return;
        }
        $this->inner->queue($invitationId);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function during(\Closure $work): mixed
    {
        $this->now = true;
        try {
            return $work();
        } finally {
            $this->now = false;
        }
    }

    public function reset(): void
    {
        $this->now = false;
    }
}
