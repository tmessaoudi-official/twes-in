<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\InvitationToken;
use Psr\Clock\ClockInterface;

/**
 * Describes a link to the person holding it, before they have signed in. Answers null for an unknown,
 * malformed, expired or used token alike: the page must not become an oracle for which of those it was.
 */
final readonly class DescribeInvitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function for(string $rawToken): ?InvitationSummary
    {
        try {
            $token = InvitationToken::fromRaw($rawToken);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $invitation = $this->invitations->ofTokenHash($token->hash());
        if (null === $invitation || !$invitation->isUsableAt($this->clock->now())) {
            return null;
        }

        return new InvitationSummary(
            $invitation->getEmail()->value,
            $invitation->getCompany()->getName(),
            $invitation->getRoleName(),
            $invitation->getExpiresAt()->format(\DATE_ATOM),
            null !== $this->users->ofEmail($invitation->getEmail()),
        );
    }
}
