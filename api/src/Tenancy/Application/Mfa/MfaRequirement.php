<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Mfa;

use App\Identity\Domain\User;
use App\Tenancy\Domain\MembershipRepository;

/**
 * Whether this user must carry a second factor.
 *
 * The question is asked of every company the user belongs to, not of the one currently chosen: a member of a
 * strict company and a relaxed one must enrol either way, or the company switcher would be the way around it
 * (ruling of 2026-09-10). A platform operator must carry one whatever their companies say (docs/SPEC.md § 7,
 * 2026-09-15, S3): the platform scope reaches every company's approval and every account.
 */
final readonly class MfaRequirement
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    public function appliesTo(User $user): bool
    {
        return $user->isPlatformOperator() || $this->memberships->anyCompanyRequiresMfa($user->getId());
    }
}
