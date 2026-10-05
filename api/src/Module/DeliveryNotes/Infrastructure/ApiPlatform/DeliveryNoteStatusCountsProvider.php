<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<DeliveryNoteStatusCountsResource> */
final readonly class DeliveryNoteStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageDeliveryNotes $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);

        return DeliveryNoteStatusCountsResource::of($this->manage->statusCounts(
            $company,
            DeliveryNoteSearchReader::read(Paging::parameters($context), Paging::text($operation), [])->withoutStatus(),
        ));
    }
}
