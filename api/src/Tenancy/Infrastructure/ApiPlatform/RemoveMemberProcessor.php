<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\LastOwner;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Application\Company\RemoveMember;
use App\Tenancy\Application\Company\RoleNotManageable;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<MemberResource, null> */
final readonly class RemoveMemberProcessor implements ProcessorInterface
{
    public function __construct(private RemoveMember $removeMember, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $userId = $uriVariables['userId'] ?? null;
        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            throw new NotFoundHttpException('No such member.');
        }

        try {
            $this->removeMember->handle($company->getId(), Uuid::fromString($userId), $this->guard->account()->getId());
        } catch (NotAMember $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (LastOwner $last) {
            throw new ConflictHttpException($last->getMessage(), $last);
        } catch (RoleNotManageable $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return null;
    }
}
