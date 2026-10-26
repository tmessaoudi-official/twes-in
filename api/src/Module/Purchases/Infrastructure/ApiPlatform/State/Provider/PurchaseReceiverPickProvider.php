<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\Receivers\ChooseReceiptReceiver;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseReceiverPickResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * The company's members for a receipt's « Reçu par », under purchase.receive (see the resource beside this), the person
 * asking first.
 *
 * @implements ProviderInterface<PurchaseReceiverPickResource>
 */
final readonly class PurchaseReceiverPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ChooseReceiptReceiver $receivers)
    {
    }

    /** @return list<PurchaseReceiverPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::RECEIVE);

        return array_map(PurchaseReceiverPickResource::of(...), $this->receivers->offered($company, $this->guard->account()->getId()));
    }
}
