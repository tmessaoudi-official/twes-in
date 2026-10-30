<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Application\Invitation\AccountDetailsRequired;
use App\Tenancy\Application\Invitation\InvitationNotUsable;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\InvitationResource;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @implements ProcessorInterface<InvitationResource, InvitationResource> */
final readonly class AcceptInvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private AcceptInvitation $accept,
        private RequestStack $requests,
        #[Target('invitation_link')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvitationResource
    {
        // The same budget as reading the link, spent before it is looked up.
        if (!$this->limiter->create($this->requests->getMainRequest()?->getClientIp() ?? '')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many attempts.');
        }
        $token = $uriVariables['token'] ?? null;
        if (!\is_string($token)) {
            throw new NotFoundHttpException('That invitation cannot be used.');
        }

        try {
            $outcome = $this->accept->handle(new AcceptRequest($token, $data->displayName, $data->password));
        } catch (InvitationNotUsable $unusable) {
            throw new NotFoundHttpException('That invitation cannot be used.', $unusable);
        } catch (AccountDetailsRequired $missing) {
            // Said on the first field left empty, as a form reads a refusal, never as a refused password.
            throw DomainRule::refused(null === $data->displayName ? 'displayName' : 'password', $missing->getMessage(), 'value_required', [], $missing);
        } catch (PasswordBreached $breached) {
            throw DomainRule::refused('password', $breached->getMessage(), 'breached', [], $breached);
        }

        $resource = new InvitationResource();
        $resource->email = null;
        $resource->companyName = $outcome->companyName;
        $resource->roleName = $outcome->roleName;
        $resource->userId = $outcome->userId;

        return $resource;
    }
}
