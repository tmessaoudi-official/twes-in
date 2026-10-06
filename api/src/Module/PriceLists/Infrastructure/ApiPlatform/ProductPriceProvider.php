<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\PriceLists\Application\ResolveUnitPrice;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<ProductPriceResource> */
final readonly class ProductPriceProvider implements ProviderInterface
{
    private const string QUANTITY = '/^[0-9]{1,11}(\\.[0-9]{1,3})?$/';

    public function __construct(private ProductRepository $products, private ResolveUnitPrice $resolve, private CompanyGuard $guard, private ClockInterface $clock)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductPriceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        $quantity = Paging::text($operation, 'quantity') ?? '1';
        if (1 !== preg_match(self::QUANTITY, $quantity) || !is_numeric($quantity) || 1 !== new Number($quantity)->compare(0)) {
            throw new UnprocessableEntityHttpException('quantity: A quantity is a decimal number above zero with at most three decimals.');
        }
        $day = Paging::text($operation, 'on');
        $on = null === $day
            ? new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'))
            : self::dayOf($day);

        $product = $this->products->ofIdInCompany(CompanyPath::identifier($uriVariables, 'productId'), $company->getId())
            ?? throw new NotFoundHttpException('No such product.');

        return ProductPriceResource::of($product->getId()->toRfc4122(), $this->resolve->of($product, Paging::identifier($operation, 'customerId'), $quantity, $on));
    }

    /** A real day: `2026-13-45` is refused, where the format alone would roll it over into another month. */
    private static function dayOf(string $day): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (false === $parsed || $parsed->format('Y-m-d') !== $day) {
            throw new UnprocessableEntityHttpException('on: A day is written YYYY-MM-DD.');
        }

        return $parsed;
    }
}
