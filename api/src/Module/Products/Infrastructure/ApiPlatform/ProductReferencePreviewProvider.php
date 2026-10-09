<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ProductReferences;
use App\Module\Products\Domain\InvalidProduct;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<ProductReferencePreviewResource> */
final readonly class ProductReferencePreviewProvider implements ProviderInterface
{
    public function __construct(private ProductReferences $references, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductReferencePreviewResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $reference = $this->references->preview($company, Paging::identifier($operation, 'categoryId'));
        } catch (InvalidProduct $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }
        $preview = new ProductReferencePreviewResource();
        $preview->reference = $reference;

        return $preview;
    }
}
