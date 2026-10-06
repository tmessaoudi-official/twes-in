<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Domain\InvalidProduct;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<PricePreviewResource, PricePreviewResource> */
final readonly class PricePreviewProcessor implements ProcessorInterface
{
    public function __construct(private CustomerPrice $prices, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PricePreviewResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        try {
            return PricePreviewResource::of($this->prices->preview($company, $data->taxComponentIds, $data->unitPriceNet, $data->quantities));
        } catch (InvalidProduct $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        } catch (InvalidDocument $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }
    }
}
