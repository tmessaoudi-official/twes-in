<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Company\ListMembers;
use App\Tenancy\Application\Company\MemberView;

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
            $this->listMembers->for($company->getId()),
        );
    }
}
