<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\OrderReceiving;
use App\Module\Purchases\Application\PurchaseOrderNotFound;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderReceivingResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<PurchaseOrderReceivingResource> */
final readonly class PurchaseOrderReceivingProvider implements ProviderInterface
{
    public function __construct(private OrderReceiving $receiving, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderReceivingResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::RECEIVE);
        $orderId = CompanyPath::identifier($uriVariables, 'purchaseOrderId');

        try {
            $offer = $this->receiving->of($company, $orderId);
        } catch (PurchaseOrderNotFound $absent) {
            throw new NotFoundHttpException('No such purchase order.', $absent);
        }

        return PurchaseOrderReceivingResource::of($orderId->toRfc4122(), $offer['places'], $offer['homes'], $offer['asksCostBasis']);
    }
}
