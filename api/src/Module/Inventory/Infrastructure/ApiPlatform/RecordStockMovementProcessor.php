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
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<StockMovementResource, StockMovementResource> */
final readonly class RecordStockMovementProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard)
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
                StockMovementResource::COUNT => $this->stock->count($company, $productId, $locationId, $data->quantity, $actor),
                // A move writes two movements; the answer is the one that LEFT, whose sourceId names the pair.
                StockMovementResource::MOVE => $this->stock->move($company, $productId, $locationId, Uuid::fromString((string) $data->toLocationId), $data->quantity, $actor)[0],
                default => $this->stock->receive($company, $productId, $locationId, $data->quantity, $actor),
            };
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockMovementResource::of($movement);
    }
}
