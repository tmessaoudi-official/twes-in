<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Invitation\DescribeInvitation;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\InvitationResource;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @implements ProviderInterface<InvitationResource> */
final readonly class InvitationProvider implements ProviderInterface
{
    public function __construct(
        private DescribeInvitation $describe,
        private RequestStack $requests,
        #[Target('invitation_link')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvitationResource
    {
        // Before the link is looked up, so a client past its budget learns nothing of any link.
        if (!$this->limiter->create($this->requests->getMainRequest()?->getClientIp() ?? '')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many attempts.');
        }
        $token = $uriVariables['token'] ?? null;
        $summary = \is_string($token) ? $this->describe->for($token) : null;

        // Unknown, malformed, expired and already used are one answer: the page must not become an oracle.
        return null === $summary ? throw new NotFoundHttpException('That invitation cannot be used.') : InvitationResource::of($summary);
    }
}
