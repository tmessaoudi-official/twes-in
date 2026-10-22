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
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Member\LastOwner;
use App\Tenancy\Application\Member\NotAMember;
use App\Tenancy\Application\Member\RemoveMember;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<MemberResource, null> */
final readonly class RemoveMemberProcessor implements ProcessorInterface
{
    public function __construct(private RemoveMember $removeMember, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $this->proof->demand($this->guard->account()->getId());
        $userId = $uriVariables['userId'] ?? null;
        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            throw new NotFoundHttpException('No such member.');
        }

        try {
            $this->removeMember->handle($company->getId(), Uuid::fromString($userId), $this->guard->account()->getId());
        } catch (NotAMember $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (LastOwner $last) {
            // Told apart from a member already there by its reason: the screen never reads the English detail.
            throw DomainRule::conflict('member', $last->getMessage(), 'last_owner', [], $last);
        } catch (RoleNotManageable $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return null;
    }
}
