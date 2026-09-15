<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Signup\SignupPolicy;

/**
 * Whether anyone may sign up, and in which countries: what the sign-in page needs before anybody has a session.
 *
 * @implements ProviderInterface<SignupResource>
 */
final readonly class SignupAvailabilityProvider implements ProviderInterface
{
    public function __construct(private SignupPolicy $policy)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SignupResource
    {
        $resource = new SignupResource();
        $resource->enabled = $this->policy->isOpen();
        $resource->countries = $resource->enabled ? $this->policy->countries() : [];

        return $resource;
    }
}
