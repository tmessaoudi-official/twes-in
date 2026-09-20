<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\AlreadyAMember;
use App\Tenancy\Application\Company\RoleNotManageable;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * One endpoint for "put this address in this company", and it always sends an invitation: nobody becomes a member
 * until they accept the mailed link, whether or not the address has an account (docs/SPEC.md § 7, 2026-09-15).
 *
 * @implements ProcessorInterface<MemberResource, MemberResource>
 */
final readonly class InviteMemberProcessor implements ProcessorInterface
{
    public function __construct(private InviteToCompany $invite, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);

        try {
            $outcome = $this->invite->handle(
                new InviteRequest($company->getId(), $data->email, $data->role),
                $this->guard->account()->getId(),
            );
        } catch (AlreadyAMember $already) {
            throw new ConflictHttpException($already->getMessage(), $already);
        } catch (UnknownRole $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        } catch (RoleNotManageable $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        $resource = new MemberResource();
        $resource->email = $outcome->email;
        $resource->role = $outcome->roleName;
        $resource->status = MemberResource::INVITED;

        return $resource;
    }
}
