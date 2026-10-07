<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\StockValue;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use BcMath\Number;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<StockValuationResource> */
final readonly class StockValuationProvider implements ProviderInterface
{
    public function __construct(private KeepStock $stock, private ProductRepository $products, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockValuationResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        if (!$this->guard->may($company, ProductPermission::COST_READ)) {
            throw new NotFoundHttpException('Not found.');
        }
        $values = $this->stock->valuation($company);
        $products = [];
        foreach ($this->products->ofIdsInCompany(array_map(static fn (StockValue $value): Uuid => $value->productId, $values), $company->getId()) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
        }

        $total = new Number('0.000');
        $lines = [];
        $estimated = false;
        foreach ($values as $value) {
            $product = $products[$value->productId->toRfc4122()] ?? null;
            $amount = new Number($value->value)->round(3);
            $total = $total->add($amount);
            $quantity = new Number($value->quantity);
            $valued = $quantity->sub($value->unvaluedQuantity);
            $lines[] = [
                'productId' => $value->productId->toRfc4122(),
                'productReference' => $product?->getReference() ?? '',
                'productName' => $product?->getDetails()->name ?? '',
                'unitCode' => $product?->getUnit()->getCode() ?? '',
                'unitName' => $product?->getUnit()->getName() ?? '',
                'quantity' => $value->quantity,
                'unitCost' => 1 === $valued->compare(0) ? new Number($value->value)->div($valued, 10)->round(4)->value : null,
                'value' => $amount->value,
                'unvaluedQuantity' => $value->unvaluedQuantity,
                'estimatedQuantity' => $value->estimatedQuantity,
            ];
            $estimated = $estimated || 0 !== new Number($value->estimatedQuantity)->compare(0);
        }
        usort($lines, static fn (array $a, array $b): int => strcmp((string) $a['productReference'], (string) $b['productReference']));

        $resource = new StockValuationResource();
        $resource->total = $total->value;
        $resource->lines = $lines;
        $resource->estimated = $estimated;

        return $resource;
    }
}
