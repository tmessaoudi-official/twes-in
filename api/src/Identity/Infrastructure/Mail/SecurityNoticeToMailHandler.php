<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Security\MailSecurityNotice;
use App\Identity\Application\Security\SecurityChange;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SecurityNoticeToMailHandler
{
    public function __construct(private MailSecurityNotice $mail)
    {
    }

    public function __invoke(SecurityNoticeToMail $message): void
    {
        $this->mail->handle(Uuid::fromString($message->userId), SecurityChange::from($message->change), $message->passkeyName);
    }
}
