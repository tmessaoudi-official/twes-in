<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Signup\SignupLinks;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<SignupResource> */
final readonly class SignupLinkProvider implements ProviderInterface
{
    public function __construct(private SignupLinks $links)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SignupResource
    {
        $token = $uriVariables['token'] ?? null;
        $signup = \is_string($token) ? $this->links->usable($token) : null;
        if (null === $signup) {
            // Closed, malformed, unknown, expired, used and taken are one answer: the page must not become an oracle.
            throw new NotFoundHttpException('That signup link cannot be used.');
        }

        $resource = new SignupResource();
        $resource->email = $signup->getEmail()->value;

        return $resource;
    }
}
