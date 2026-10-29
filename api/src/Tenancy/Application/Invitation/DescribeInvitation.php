<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Identity\Domain\Repository\UserRepository;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\InvitationRepository;
use App\Tenancy\Domain\ValueObject\InvitationToken;
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

        // A company the operator closed is said before the form, as signing in would say it, not after the person joins.
        $company = $invitation->getCompany();
        $closed = Company::STATUS_SUSPENDED === $company->getStatus();

        return new InvitationSummary(
            $invitation->getEmail()->value,
            $company->getName(),
            $invitation->getRoleName(),
            $invitation->getExpiresAt()->format(\DATE_ATOM),
            null !== $this->users->ofEmail($invitation->getEmail()),
            $closed && !$company->wasOpened(),
            $closed && $company->wasOpened(),
            $closed ? $company->getSuspensionReason() : null,
        );
    }
}
