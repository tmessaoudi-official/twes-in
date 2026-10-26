<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\Replay\SettleStockMoveFailures;
use App\Module\Inventory\Application\Replay\StockMoveFailureNotFound;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMoveFailureResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Remettre le stock à jour »: the failed move made again, which answers whether the stock moved.
 *
 * @implements ProcessorInterface<null, StockMoveFailureResource>
 */
final readonly class ReplayStockMoveProcessor implements ProcessorInterface
{
    public function __construct(private SettleStockMoveFailures $failures, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockMoveFailureResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $failureId = CompanyPath::identifier($uriVariables, 'failureId');

        try {
            $failure = $this->failures->find($company, $failureId);
            $settled = $this->failures->replay($company, $failureId, $this->guard->account()->getId());
        } catch (StockMoveFailureNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return StockMoveFailureResource::of($failure, $settled);
    }
}
