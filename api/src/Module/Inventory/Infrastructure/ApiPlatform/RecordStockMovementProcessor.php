<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ReceiptDocuments;
use App\Module\Inventory\Domain\CostBasis;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLossReason;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<StockMovementResource, StockMovementResource> */
final readonly class RecordStockMovementProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private ReceiptDocuments $documents, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockMovementResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $productId = Uuid::fromString($data->productId);
        $locationId = Uuid::fromString($data->locationId);
        $actor = $this->guard->account()->getId();

        try {
            $movement = match ($data->operation) {
                StockMovementResource::COUNT => $this->stock->count($company, $productId, $locationId, $data->quantity, $actor, $data->lot()),
                // A move writes two movements; the answer is the one that LEFT, whose sourceId names the pair.
                StockMovementResource::MOVE => $this->stock->move($company, $productId, $locationId, Uuid::fromString((string) $data->toLocationId), $data->quantity, $actor, $data->lot())[0],
                StockMovementResource::LOSS => $this->stock->writeOff($company, $productId, $locationId, $data->quantity, StockLossReason::from((string) $data->reason), $data->note, $actor, $data->lot()),
                default => $this->receive($company, $productId, $locationId, $data, $actor),
            };
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockMovementResource::of($movement);
    }

    /**
     * A writer who cannot read costs neither types one nor applies one, as for a product: what they send is left out and
     * the receipt is valued at the average.
     */
    private function receive(Company $company, Uuid $productId, Uuid $locationId, StockMovementResource $data, Uuid $actor): StockMovement
    {
        $sees = $this->guard->may($company, ProductPermission::COST_READ);

        return $this->stock->receive($company, $productId, $locationId, $data->quantity, $actor, $data->lot(), $sees ? $data->unitCost : null, $sees && null !== $data->applyCost ? CostBasis::from($data->applyCost) : null, $this->documents->named($company, $data->vendorId, $data->supplierReference, $data->receivedOn));
    }
}
