<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\StockMovementNotFound;
use App\Module\Inventory\Domain\Exception\InvalidStockMovement;
use App\Module\Inventory\Domain\Exception\StockMovementCostKnown;
use App\Module\Inventory\Domain\ValueObject\CostBasis;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\ReceiptCostEntryResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMovementResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<ReceiptCostEntryResource, StockMovementResource> */
final readonly class EnterReceiptCostProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockMovementResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $this->guard->companyForActing($companyId, StockPermission::WRITE);
        $company = $this->guard->companyForActing($companyId, ProductPermission::COST_READ);

        try {
            $receipt = $this->stock->enterCost($company, CompanyPath::identifier($uriVariables, 'movementId'), $data->unitCost, null === $data->applyCost ? null : CostBasis::from($data->applyCost), $this->guard->account()->getId());
        } catch (StockMovementNotFound $absent) {
            throw new NotFoundHttpException('No such stock movement.', $absent);
        } catch (StockMovementCostKnown $known) {
            throw new ConflictHttpException($known->getMessage(), $known);
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockMovementResource::of($receipt);
    }
}
