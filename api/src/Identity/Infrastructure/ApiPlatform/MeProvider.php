<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** @implements ProviderInterface<Me> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(private Security $security, private MeFactory $me)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // The operation security expression runs first; this is the type guard, not the access check.
            throw new AccessDeniedException();
        }

        return $this->me->for($account);
    }
}
