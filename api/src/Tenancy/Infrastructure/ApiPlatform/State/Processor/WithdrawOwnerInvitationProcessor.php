<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\Invitation\InvitationNotFound;
use App\Tenancy\Application\Invitation\WithdrawInvitation;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\PlatformOwnerInvitationResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back an owner's invitation the platform sent, before the wrong address can join as owner. The operation's
 * security requires an operator and the platform's step-up wall a recent proof, so the company guard, which answers by
 * membership, is not asked, as inviting the owner does not ask it.
 *
 * @implements ProcessorInterface<PlatformOwnerInvitationResource, null>
 */
final readonly class WithdrawOwnerInvitationProcessor implements ProcessorInterface
{
    public function __construct(private WithdrawInvitation $withdraw, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }
        $invitationId = $uriVariables['invitationId'] ?? null;
        if (!\is_string($invitationId) || !Uuid::isValid($invitationId)) {
            throw new NotFoundHttpException('No such invitation.');
        }

        try {
            $this->withdraw->handleOwnerInvitation(CompanyPath::identifier($uriVariables, 'companyId'), Uuid::fromString($invitationId), $account->getId());
        } catch (InvitationNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (RoleNotManageable $refused) {
            // An operator who is also a member below owner of this company is bounded by that role, as in the company.
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return null;
    }
}
