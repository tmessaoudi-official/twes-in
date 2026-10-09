<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's half of a notification's mail. Everything is asked again at sending time, since the person may have
 * turned that kind's mail off, left the company, lost the role that is told it or seen its module switched off since
 * it was queued: then nothing is sent.
 */
final readonly class MailNotification
{
    public function __construct(
        private UserRepository $users,
        private NotificationKinds $kinds,
        private NotificationPreferences $preferences,
        private MailStopTokens $tokens,
        private NotificationMailer $mailer,
    ) {
    }

    /** @param array<string, scalar|null> $payload */
    public function handle(Uuid $userId, ?Uuid $companyId, string $type, array $payload): void
    {
        if (true !== $this->kinds->get($type)?->mailed) {
            return;
        }
        $user = $this->users->ofId($userId);
        if (null === $user || !$user->isActive()) {
            return;
        }
        $choice = $this->preferences->choiceOf($userId, $companyId, $type);
        if (null === $choice || !$choice->email) {
            return;
        }

        $this->mailer->send(new NotificationMail(
            $user->getEmail()->value,
            $user->getLocale(),
            $type,
            $choice->companyName,
            $payload,
            $this->tokens->issue(new MailStop($userId, $companyId, $type)),
        ));
    }
}
