<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Purchases\Application\ManagePurchaseOrders;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Infrastructure\ApiPlatform\Output\PurchaseOrderView;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProcessorInterface<PurchaseOrderResource, PurchaseOrderResource> */
final readonly class CreatePurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private ManagePurchaseOrders $manage, private PurchaseOrderView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::WRITE);

        try {
            $order = $this->manage->create($company, $data->input(), $this->guard->may($company, ProductPermission::COST_READ), $this->guard->account()->getId());
        } catch (InvalidPurchaseOrder $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($order);
    }
}
