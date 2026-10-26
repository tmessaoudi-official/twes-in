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
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use App\Tenancy\Application\Member\AlreadyAMember;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Application\Member\UnknownRole;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
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
    public function __construct(private InviteToCompany $invite, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $request = new InviteRequest($company->getId(), $data->email, $data->role);

        try {
            // What the role refuses is said first: nobody proves who they are only to be told no (hunt N-1).
            $this->invite->assertMayInvite($request, $this->guard->account()->getId());
            $this->proof->demand($this->guard->account()->getId());
            $outcome = $this->invite->handle($request, $this->guard->account()->getId());
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
