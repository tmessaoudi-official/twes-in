<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Member\ListMembers;
use App\Tenancy\Application\Member\MemberView;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;

/** @implements ProviderInterface<MemberResource> */
final readonly class MemberCollectionProvider implements ProviderInterface
{
    public function __construct(private ListMembers $listMembers, private CompanyGuard $guard)
    {
    }

    /** @return list<MemberResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::READ);

        return array_map(
            static fn (MemberView $view) => MemberResource::of($view),
            $this->listMembers->for($company->getId(), $this->guard->account()->getId()),
        );
    }
}
