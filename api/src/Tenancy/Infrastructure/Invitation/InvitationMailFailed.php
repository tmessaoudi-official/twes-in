<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Invitation;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * An invitation the worker gave up mailing, after its retries, is marked so « À surveiller » shows it to whoever may
 * invite (docs/SPEC.md § 7, Messenger transport, row 56); the message itself waits in the failed transport for
 * `messenger:failed:retry`. A failure the worker will try again marks nothing.
 *
 * Written straight to the table by id: when the handler failed on the database, its entity manager is closed, and a
 * flush through it would lose the mark without a word. An invitation accepted meanwhile is left as it is.
 */
#[AsEventListener]
final readonly class InvitationMailFailed
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof InvitationToMail) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE invitation SET mail_failed_at = :at WHERE id = :id AND accepted_at IS NULL',
            ['at' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $message->invitationId],
        );
    }
}
