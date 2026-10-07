<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Audit\Application\ActivityEntry;
use App\Audit\Application\ReadActivity;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;

/**
 * One page of the company's journal. A sign-in address is a person's whereabouts, so it is left out for a reader who
 * does not manage the team (docs/SPEC.md § 7, 2026-09-26 23:04: owners and admins only).
 *
 * @implements ProviderInterface<ActivityResource>
 */
final readonly class ActivityCollectionProvider implements ProviderInterface
{
    public function __construct(private ReadActivity $read, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<ActivityResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), AuditPermission::READ);
        $search = ActivitySearchReader::read(Paging::parameters($context), Paging::text($operation), $company);
        $withAddress = $this->guard->may($company, MemberPermission::WRITE);

        return $this->paging->paginator(
            $this->read->search($company, $search, $this->paging->request($operation, $context)),
            static fn (ActivityEntry $entry): ActivityResource => ActivityResource::of($entry, $withAddress),
        );
    }
}
