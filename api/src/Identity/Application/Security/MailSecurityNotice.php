<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ReaderFormats;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's half of a security notice: the account read again at sending time, in its language, at the address it
 * has then. An account gone since is told nothing; a closed one still is, as a change to how it signs in matters most
 * to an account someone else may be using.
 *
 * When a lock ends is written as its reader writes a moment, in the time zone of the company a sign-in opens for them
 * (an account has none of its own), UTC for one who belongs to no company; the mail names the zone.
 */
final readonly class MailSecurityNotice
{
    public function __construct(
        private UserRepository $users,
        private SecurityNoticeMailer $mailer,
        private ReaderFormats $formats,
        private MembershipRepository $memberships,
    ) {
    }

    public function handle(Uuid $userId, SecurityChange $change, ?string $passkeyName, ?\DateTimeImmutable $until = null): void
    {
        $user = $this->users->ofId($userId);
        if (null === $user) {
            return;
        }

        $lock = null;
        if (null !== $until) {
            $company = $this->memberships->toOpenAtSignIn($userId)?->getCompany();
            $zone = null === $company ? 'UTC' : $company->getTimezone();
            $lock = new LockEnd(
                $until->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i'),
                $zone,
                $this->formats->of($userId, $company?->getId())['dateFormat'],
            );
        }

        $this->mailer->send(new SecurityNoticeMail($user->getEmail()->value, $user->getLocale(), $change, $passkeyName, $lock));
    }
}
