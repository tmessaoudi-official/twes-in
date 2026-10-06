<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Invitation;

use App\Tenancy\Application\Invitation\MailInvitation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class InvitationToMailHandler
{
    public function __construct(private MailInvitation $mail)
    {
    }

    public function __invoke(InvitationToMail $message): void
    {
        $this->mail->handle(Uuid::fromString($message->invitationId));
    }
}
