<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\PickProducts;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A few products for the delivery note form's picker, under the delivery note's own permission (see the resource
 * beside this).
 *
 * @implements ProviderInterface<DeliveryNoteProductPickResource>
 */
final readonly class DeliveryNoteProductPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickProducts $products)
    {
    }

    /** @return list<DeliveryNoteProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);

        $ids = Paging::uuids($operation, 'ids');

        return array_map(DeliveryNoteProductPickResource::of(...), [] === $ids
            ? $this->products->matching($company, Paging::text($operation) ?? '')
            : $this->products->byIds($company, $ids));
    }
}
