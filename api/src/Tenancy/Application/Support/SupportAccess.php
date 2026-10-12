<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\SupportGrant;
use App\Tenancy\Domain\SupportGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Who reads a company through « Accès support », and what that lets them read. Only a platform operator who is not a
 * member, under the company's live grant, and who proves who they are with a second factor of their own: a password
 * alone never opens a company's books to someone the company did not invite. Read, never more, and never what goods
 * cost, the one read a company keeps from its own staff. Asked on every request, never remembered: the worker serves
 * many requests on one kernel, and a window must close on time.
 */
final readonly class SupportAccess
{
    /** What a refused write or export answers, which the screen names. */
    public const string READ_ONLY = 'support_read_only';

    /** The role the switcher and the session show for a company read through a grant. */
    public const string ROLE = 'support';

    /** What goods cost, named as the products module names it, which a test keeps in step. */
    public const string COST = 'product.cost.read';

    public function __construct(
        private UserRepository $users,
        private MembershipRepository $memberships,
        private SupportGrantRepository $grants,
        private SecondFactors $secondFactors,
        private ClockInterface $clock,
    ) {
    }

    /** The grant through which this account reads the company, or null when it reads it otherwise or not at all. */
    public function grantOf(Uuid $userId, Uuid $companyId): ?SupportGrant
    {
        $user = $this->users->ofId($userId);
        if (null === $user || !$user->isPlatformOperator() || !$this->secondFactors->has($user)) {
            return null;
        }
        // An operator who is also a member works as a member, with what their role grants.
        if (null !== $this->memberships->ofUserInCompany($userId, $companyId)) {
            return null;
        }

        return $this->grants->liveIn($companyId, $this->clock->now());
    }

    /**
     * Every company this account may read through a grant, which its switcher offers beside its memberships.
     *
     * @return list<SupportGrant>
     */
    public function grantsFor(Uuid $userId, int $limit): array
    {
        $user = $this->users->ofId($userId);
        if (null === $user || !$user->isPlatformOperator() || !$this->secondFactors->has($user)) {
            return [];
        }

        return $this->grants->liveAt($this->clock->now(), $limit);
    }

    /** Until when members and support see that support may read the company, null while it may not. */
    public function liveUntil(Uuid $companyId): ?\DateTimeImmutable
    {
        return $this->grants->liveIn($companyId, $this->clock->now())?->getEndsAt();
    }

    public static function permits(string $permission): bool
    {
        return str_ends_with($permission, '.read') && self::COST !== $permission;
    }
}
