<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Application\Mfa\MfaRequirement;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use Symfony\Component\Uid\Uuid;

/** The signed-in state of the authenticated account: the user row, the Tenancy working context and its modules. */
final readonly class MeFactory
{
    public function __construct(
        private UserRepository $users,
        private DescribeWorkingContext $describeWorkingContext,
        private MfaRequirement $mfaRequirement,
        private ModuleStates $modules,
    ) {
    }

    public function for(SecurityUser $account): Me
    {
        $user = $this->users->ofId($account->getId()) ?? throw new \LogicException('An authenticated account always has a user row.');
        $context = $this->describeWorkingContext->for($user->getId());
        $modules = null === $context ? [] : $this->modules->enabledKeys(Uuid::fromString($context->companyId));

        return Me::of($user, $context, $this->mfaRequirement->appliesTo($user), $modules);
    }
}
