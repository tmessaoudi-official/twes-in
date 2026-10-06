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
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<StockReceiptResource, StockReceiptResource> */
final readonly class RecordStockReceiptProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private ReceiptDocuments $documents, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockReceiptResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        // As for one receipt: a writer who cannot read costs neither types one nor applies one.
        $sees = $this->guard->may($company, ProductPermission::COST_READ);
        $parts = array_map(
            static fn (array $part): array => ['locationId' => Uuid::fromString($part['locationId']), 'quantity' => $part['quantity']],
            $data->parts,
        );

        try {
            $movements = $this->stock->receiveSplit(
                $company,
                Uuid::fromString($data->productId),
                $parts,
                $this->guard->account()->getId(),
                null === $data->lotCode ? null : new NamedLot($data->lotCode, null === $data->lotExpiresOn ? null : new \DateTimeImmutable($data->lotExpiresOn)),
                $sees ? $data->unitCost : null,
                $sees && null !== $data->applyCost ? CostBasis::from($data->applyCost) : null,
                $this->documents->named($company, $data->vendorId, $data->supplierReference, $data->receivedOn),
                !$sees,
            );
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $data->id = $movements[0]->getId()->toRfc4122();
        $data->vendorId = $movements[0]->getVendor()?->getId()->toRfc4122();
        $data->supplierReference = $movements[0]->getSupplierReference();
        $data->receivedOn = $movements[0]->getReceivedOn()?->format('Y-m-d');
        $data->movementIds = array_map(static fn ($movement): string => $movement->getId()->toRfc4122(), $movements);
        $data->parts = array_map(
            static fn ($movement): array => ['locationId' => $movement->getLocation()->getId()->toRfc4122(), 'quantity' => $movement->getQuantity()],
            $movements,
        );

        return $data;
    }
}
