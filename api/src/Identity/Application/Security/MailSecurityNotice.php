<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Identity\Domain\Repository\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's half of a security notice: the account read again at sending time, in its language, at the address it
 * has then. An account gone since is told nothing; a closed one still is, as a change to how it signs in matters most
 * to an account someone else may be using.
 */
final readonly class MailSecurityNotice
{
    public function __construct(private UserRepository $users, private SecurityNoticeMailer $mailer)
    {
    }

    public function handle(Uuid $userId, SecurityChange $change, ?string $passkeyName): void
    {
        $user = $this->users->ofId($userId);
        if (null === $user) {
            return;
        }

        $this->mailer->send(new SecurityNoticeMail($user->getEmail()->value, $user->getLocale(), $change, $passkeyName));
    }
}
