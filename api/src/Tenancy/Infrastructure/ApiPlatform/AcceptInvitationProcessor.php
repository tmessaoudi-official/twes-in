<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Application\Invitation\AccountDetailsRequired;
use App\Tenancy\Application\Invitation\InvitationNotUsable;
use App\Tenancy\Application\Invitation\PasswordBreached;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<InvitationResource, InvitationResource> */
final readonly class AcceptInvitationProcessor implements ProcessorInterface
{
    public function __construct(private AcceptInvitation $accept)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvitationResource
    {
        $token = $uriVariables['token'] ?? null;
        if (!\is_string($token)) {
            throw new NotFoundHttpException('That invitation cannot be used.');
        }

        try {
            $outcome = $this->accept->handle(new AcceptRequest($token, $data->displayName, $data->password));
        } catch (InvitationNotUsable $unusable) {
            throw new NotFoundHttpException('That invitation cannot be used.', $unusable);
        } catch (AccountDetailsRequired $missing) {
            throw new UnprocessableEntityHttpException($missing->getMessage(), $missing);
        } catch (PasswordBreached $breached) {
            throw new UnprocessableEntityHttpException($breached->getMessage(), $breached);
        }

        $resource = new InvitationResource();
        $resource->email = null;
        $resource->companyName = $outcome->companyName;
        $resource->roleName = $outcome->roleName;
        $resource->userId = $outcome->userId;

        return $resource;
    }
}
