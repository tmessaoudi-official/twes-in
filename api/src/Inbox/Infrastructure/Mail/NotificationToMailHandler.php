<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use App\Inbox\Application\MailNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class NotificationToMailHandler
{
    public function __construct(private MailNotification $mail)
    {
    }

    public function __invoke(NotificationToMail $message): void
    {
        $this->mail->handle(
            Uuid::fromString($message->userId),
            null === $message->companyId ? null : Uuid::fromString($message->companyId),
            $message->type,
            $message->payload,
        );
    }
}
