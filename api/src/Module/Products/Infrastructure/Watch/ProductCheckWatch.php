<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Watch;

use App\Module\Products\Application\ProductCheckScopes;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\ProductCheckReason;
use App\Module\Products\Domain\ValueObject\ProductSearch;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;

/**
 * The active products « À vérifier »: marked by a person, or lacking what a product sold here needs. Read through the
 * products list's own search, so the count, the rows and the list's filter are one statement. The subject is shown to
 * whoever reads products, so it never says a cost is missing: that reason is the list's, to a cost reader.
 */
final readonly class ProductCheckWatch implements DeclaresWatch
{
    public const string TO_CHECK = 'product.to_check';

    public function __construct(private ProductRepository $products, private ProductCheckScopes $scopes)
    {
    }

    public function key(): string
    {
        return 'product_check';
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::READ;
    }

    public function kinds(): array
    {
        return [self::TO_CHECK];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        return $this->read($kind, $company, new PageRequest(1, 1))->total;
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $scope = $this->scopes->of($company, false);
        $page = $this->read($kind, $company, $request);

        return new Page(array_map(static function (Product $product) use ($scope): WatchItem {
            $mark = $product->getCheckMark();

            return new WatchItem(self::TO_CHECK, $product->getId()->toRfc4122(), [
                'product' => $product->getDetails()->name,
                'reference' => $product->getReference(),
                'reasons' => implode(',', array_map(static fn (ProductCheckReason $reason): string => $reason->value, $product->checkReasons($scope))),
                'marked' => $mark->marked ? 1 : 0,
                'note' => $mark->note ?? '',
            ]);
        }, $page->items), $page->total, $request);
    }

    /** @return Page<Product> */
    private function read(string $kind, Company $company, PageRequest $request): Page
    {
        if (self::TO_CHECK !== $kind) {
            throw new \LogicException(\sprintf('The product check watch has no %s.', $kind));
        }
        $search = new ProductSearch(active: true, toCheck: true)->withCheckScope($this->scopes->of($company, false));

        return $this->products->search($company->getId(), $search, $request);
    }
}
