<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Tenancy\Domain\Model\SupportGrant;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\SupportGrantRepository;
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
        if (!$this->mayRead($this->users->ofId($userId), $companyId)) {
            return null;
        }
        $grant = $this->grants->liveIn($companyId, $this->clock->now());

        // A grant naming another operator lets this one in no more than no grant at all.
        return null !== $grant && $grant->opensTo($userId) ? $grant : null;
    }

    /**
     * Every company this account may read through a grant, which its switcher offers beside its memberships: those
     * opened to every operator, and those opened to this one by name.
     *
     * @return list<SupportGrant>
     */
    public function grantsFor(Uuid $userId, int $limit): array
    {
        $user = $this->users->ofId($userId);
        if (!$this->able($user)) {
            return [];
        }

        return $this->grants->liveAt($this->clock->now(), $limit, $userId);
    }

    /**
     * The operators an owner may open the company to by name: exactly those a grant would let in, so a name offered is
     * never one that reads nothing.
     *
     * @return list<User>
     */
    public function operatorsFor(Uuid $companyId): array
    {
        return array_values(array_filter($this->users->platformOperators(), fn (User $operator): bool => $this->mayRead($operator, $companyId)));
    }

    /** Until when members and support see that support may read the company, null while it may not. */
    public function liveUntil(Uuid $companyId): ?\DateTimeImmutable
    {
        return $this->grants->liveIn($companyId, $this->clock->now())?->getEndsAt();
    }

    /** An active platform operator proving who they are with a second factor of their own. */
    private function able(?User $user): bool
    {
        return null !== $user && $user->isActive() && $user->isPlatformOperator() && $this->secondFactors->has($user);
    }

    /** Able, and no member of the company: an operator who is also a member works as a member, with what their role grants. */
    private function mayRead(?User $user, Uuid $companyId): bool
    {
        return null !== $user && $this->able($user) && null === $this->memberships->ofUserInCompany($user->getId(), $companyId);
    }

    public static function permits(string $permission): bool
    {
        return str_ends_with($permission, '.read') && self::COST !== $permission;
    }
}
