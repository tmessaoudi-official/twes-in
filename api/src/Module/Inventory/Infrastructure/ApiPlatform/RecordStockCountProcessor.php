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
use App\Module\Inventory\Domain\NamedLot;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<StockCountResource, StockCountResource> */
final readonly class RecordStockCountProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockCountResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $parts = array_map(
            static fn (array $part): array => ['locationId' => Uuid::fromString($part['locationId']), 'quantity' => $part['quantity']],
            $data->parts,
        );

        try {
            $movements = $this->stock->countSplit(
                $company,
                Uuid::fromString($data->productId),
                $parts,
                $this->guard->account()->getId(),
                null === $data->lotCode ? null : new NamedLot($data->lotCode, null === $data->lotExpiresOn ? null : new \DateTimeImmutable($data->lotExpiresOn)),
            );
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $data->id = $movements[0]->getId()->toRfc4122();
        $data->movementIds = array_map(static fn ($movement): string => $movement->getId()->toRfc4122(), $movements);
        $data->parts = array_map(
            // What was found, as the stock list writes it: the counts were taken, so every quantity is a number.
            static fn (array $part): array => ['locationId' => $part['locationId']->toRfc4122(), 'quantity' => is_numeric($part['quantity']) ? bcadd($part['quantity'], '0', 3) : $part['quantity']],
            $parts,
        );

        return $data;
    }
}
