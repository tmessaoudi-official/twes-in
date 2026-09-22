<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ScanProducts;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ProductScanResource> */
final readonly class ProductScanProvider implements ProviderInterface
{
    public function __construct(private ScanProducts $scans, private CompanyGuard $guard, private ClockInterface $clock)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductScanResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        $found = $this->scans->find($company, Paging::text($operation, 'code') ?? '');
        if (null === $found) {
            throw new NotFoundHttpException('No product of this company answers to this code.');
        }

        return ProductScanResource::of($found[0], $found[1], (int) $this->clock->now()->format('Y'));
    }
}
