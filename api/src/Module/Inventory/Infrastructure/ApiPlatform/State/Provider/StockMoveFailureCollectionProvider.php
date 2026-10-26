<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\Replay\SettleStockMoveFailures;
use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMoveFailureResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<StockMoveFailureResource> */
final readonly class StockMoveFailureCollectionProvider implements ProviderInterface
{
    public function __construct(private SettleStockMoveFailures $failures, private CompanyGuard $guard)
    {
    }

    /** @return list<StockMoveFailureResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $filters = $context['filters'] ?? [];
        $document = \is_array($filters) && \is_string($filters['document'] ?? null) ? $filters['document'] : '';
        if (!Uuid::isValid($document)) {
            throw new BadRequestHttpException('document: name the delivery note, invoice or credit note by its id.');
        }

        return array_map(static fn (StockMoveFailure $failure): StockMoveFailureResource => StockMoveFailureResource::of($failure), $this->failures->ofDocument($company, Uuid::fromString($document)));
    }
}
