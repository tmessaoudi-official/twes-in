<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Tenancy\Application\Invitation\InvitationNotFound;
use App\Tenancy\Application\Invitation\WithdrawInvitation;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back an invitation, with the right to invite and a recent proof, as sending one asks.
 *
 * @implements ProcessorInterface<MemberResource, null>
 */
final readonly class WithdrawInvitationProcessor implements ProcessorInterface
{
    public function __construct(private WithdrawInvitation $withdraw, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $this->proof->demand($this->guard->account()->getId());
        $invitationId = $uriVariables['invitationId'] ?? null;
        if (!\is_string($invitationId) || !Uuid::isValid($invitationId)) {
            throw new NotFoundHttpException('No such invitation.');
        }

        try {
            $this->withdraw->handle($company->getId(), Uuid::fromString($invitationId), $this->guard->account()->getId());
        } catch (InvitationNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (RoleNotManageable $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return null;
    }
}
