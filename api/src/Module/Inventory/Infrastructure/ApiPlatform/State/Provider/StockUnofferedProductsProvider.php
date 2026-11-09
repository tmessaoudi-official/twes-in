<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Products\Application\PickProducts;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Infrastructure\ApiPlatform\Output\UnofferedProducts;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * What a stock screen's product picker leaves out of what the words find, read with the stock permission as the picker
 * is: the goods switched off, the services, and the active goods whose stock is not kept.
 *
 * The last is read the way the picker reads it (`StockProductPickProvider`): the setting walked over the same window
 * of matching goods, so the count says what the picker saw, at most that window, and never a total it did not read.
 *
 * @implements ProviderInterface<UnofferedProducts>
 */
final readonly class StockUnofferedProductsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private PickProducts $picks,
        private ProductRepository $products,
        private KeepStock $stock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnofferedProducts
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $words = TypedText::trimmed(Paging::text($operation) ?? '');
        if ('' === $words) {
            return new UnofferedProducts();
        }

        $matching = $this->products->pick($company->getId(), $words, StockProductPickProvider::SCANNED, ProductKind::Goods);
        $notStocked = \count(array_filter($matching, fn (Product $product): bool => !$this->stock->tracked($product)));

        return UnofferedProducts::of($this->picks->unoffered($company, $words, ProductKind::Goods), $notStocked);
    }
}
