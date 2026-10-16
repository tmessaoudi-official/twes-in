<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\PickCustomers;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteCustomerPickResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * A few customers for the delivery note form's picker, under the delivery note's own permission (see the resource
 * beside this).
 *
 * @implements ProviderInterface<DeliveryNoteCustomerPickResource>
 */
final readonly class DeliveryNoteCustomerPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickCustomers $customers)
    {
    }

    /** @return list<DeliveryNoteCustomerPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);

        $ids = Paging::uuids($operation, 'ids');

        return array_map(DeliveryNoteCustomerPickResource::of(...), [] === $ids
            ? $this->customers->matching($company, Paging::text($operation) ?? '')
            : $this->customers->byIds($company, $ids));
    }
}
