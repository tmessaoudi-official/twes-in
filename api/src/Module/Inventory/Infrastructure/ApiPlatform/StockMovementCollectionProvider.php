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
use App\Module\Inventory\Domain\StockMovement;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<StockMovementResource> */
final readonly class StockMovementCollectionProvider implements ProviderInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard)
    {
    }

    /** @return list<StockMovementResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $filters = $context['filters'] ?? [];
        $named = \is_array($filters) ? ($filters['productId'] ?? null) : null;
        if (null !== $named && (!\is_string($named) || !Uuid::isValid($named))) {
            throw new BadRequestHttpException('productId: name a product by its id.');
        }

        return array_map(static fn (StockMovement $movement) => StockMovementResource::of($movement), $this->stock->movementsOf($company, null === $named ? null : Uuid::fromString($named)));
    }
}
