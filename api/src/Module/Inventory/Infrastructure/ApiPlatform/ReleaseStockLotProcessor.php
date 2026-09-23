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
use App\Module\Inventory\Application\StockLotNotFound;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Releases an expired lot. A lot still in date is a 422: there is nothing to release.
 *
 * @implements ProcessorInterface<mixed, StockLotResource>
 */
final readonly class ReleaseStockLotProcessor implements ProcessorInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockLotResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);

        try {
            $lot = $this->stock->release($company, CompanyPath::identifier($uriVariables, 'lotId'), $this->guard->account()->getId());
        } catch (StockLotNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidStockMovement $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockLotResource::of($lot);
    }
}
