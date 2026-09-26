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
use App\Module\DeliveryNotes\Domain\DeliveryNoteSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<DeliveryNoteStatusCountsResource> */
final readonly class DeliveryNoteStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageDeliveryNotes $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);
        // The `uuid` format has already refused anything that is not an identifier, with a 422.
        $customer = Paging::value($operation, 'customerId');

        return DeliveryNoteStatusCountsResource::of($this->manage->statusCounts($company, new DeliveryNoteSearch(
            Paging::text($operation),
            null,
            \is_string($customer) ? Uuid::fromString($customer) : null,
        )));
    }
}
