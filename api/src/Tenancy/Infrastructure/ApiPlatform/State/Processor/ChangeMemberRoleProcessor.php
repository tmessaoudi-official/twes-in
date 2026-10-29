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
use App\Tenancy\Application\Member\ChangeMemberRole;
use App\Tenancy\Application\Member\LastOwner;
use App\Tenancy\Application\Member\ListMembers;
use App\Tenancy\Application\Member\NotAMember;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Application\Member\UnknownRole;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A member's role changed, with the right to manage members and a recent proof, as inviting and removing ask; answers
 * the member's row as the list shows it.
 *
 * @implements ProcessorInterface<MemberResource, MemberResource>
 */
final readonly class ChangeMemberRoleProcessor implements ProcessorInterface
{
    public function __construct(private ChangeMemberRole $change, private ListMembers $members, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $userId = $uriVariables['userId'] ?? null;
        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            throw new NotFoundHttpException('No such member.');
        }

        try {
            // What the role refuses is said first: nobody proves who they are only to be told no (hunt N-1).
            $this->change->assertMayChange($company->getId(), Uuid::fromString($userId), $data->role, $this->guard->account()->getId());
            $this->proof->demand($this->guard->account()->getId());
            $this->change->handle($company->getId(), Uuid::fromString($userId), $data->role, $this->guard->account()->getId());
        } catch (NotAMember $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (UnknownRole $unknown) {
            throw new UnprocessableEntityHttpException($unknown->getMessage(), $unknown);
        } catch (LastOwner $last) {
            throw DomainRule::conflict('role', $last->getMessage(), 'last_owner', [], $last);
        } catch (RoleNotManageable $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        foreach ($this->members->for($company->getId(), $this->guard->account()->getId()) as $view) {
            if ($userId === $view->userId) {
                return MemberResource::of($view);
            }
        }

        throw new NotFoundHttpException('No such member.');
    }
}
