<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Invitation\DescribeInvitation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<InvitationResource> */
final readonly class InvitationProvider implements ProviderInterface
{
    public function __construct(private DescribeInvitation $describe)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvitationResource
    {
        $token = $uriVariables['token'] ?? null;
        $summary = \is_string($token) ? $this->describe->for($token) : null;

        // Unknown, malformed, expired and already used are one answer: the page must not become an oracle.
        return null === $summary ? throw new NotFoundHttpException('That invitation cannot be used.') : InvitationResource::of($summary);
    }
}
