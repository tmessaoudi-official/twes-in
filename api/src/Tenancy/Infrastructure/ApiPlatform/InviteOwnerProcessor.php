<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\Company\AlreadyAMember;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\RoleNotManageable;
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use App\Tenancy\Domain\Role;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Invites the owner of the company in the path; the operation's security has already required an operator, so the
 * company guard, which answers by membership, is not asked.
 *
 * @implements ProcessorInterface<PlatformOwnerInvitationResource, PlatformOwnerInvitationResource>
 */
final readonly class InviteOwnerProcessor implements ProcessorInterface
{
    public function __construct(private InviteToCompany $invite, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformOwnerInvitationResource
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }

        try {
            $outcome = $this->invite->handle(
                new InviteRequest(CompanyPath::identifier($uriVariables, 'companyId'), $data->email, Role::OWNER),
                $account->getId(),
            );
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException($notFound->getMessage(), $notFound);
        } catch (AlreadyAMember $already) {
            throw new ConflictHttpException($already->getMessage(), $already);
        } catch (RoleNotManageable $refused) {
            // An operator who is also a member below owner of this company is bounded by that role, as in the company.
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        $resource = new PlatformOwnerInvitationResource();
        $resource->email = $outcome->email;

        return $resource;
    }
}
