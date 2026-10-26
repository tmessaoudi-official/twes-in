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
 * « C'est réglé »: the failure said settled without moving anything, the stock having been set right otherwise.
 *
 * @implements ProcessorInterface<StockMoveFailureResource, null>
 */
final readonly class SettleStockMoveProcessor implements ProcessorInterface
{
    public function __construct(private SettleStockMoveFailures $failures, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);

        try {
            $this->failures->settled($company, CompanyPath::identifier($uriVariables, 'failureId'), $this->guard->account()->getId());
        } catch (StockMoveFailureNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
