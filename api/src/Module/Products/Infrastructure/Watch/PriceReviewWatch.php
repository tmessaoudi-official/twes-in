<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Watch;

use App\Module\Products\Domain\ValueObject\PriceReview;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * The products whose margin a receipt's cost moved under a kept price, until someone adjusts the price or keeps it,
 * shown to whoever may read costs. When the cost moved is not kept, so they come by reference, as the shelves are walked.
 */
final readonly class PriceReviewWatch implements DeclaresWatch
{
    public const string MARGIN_MOVED = 'product.margin_moved';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'product_price';
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::COST_READ;
    }

    public function kinds(): array
    {
        return [self::MARGIN_MOVED];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);

        return (int) self::text($this->connection->fetchOne('SELECT COUNT(*) FROM ('.self::rows().') watched', ['company' => $company->getId()->toRfc4122()]));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $rows = $this->connection->fetchAllAssociative(
            self::rows().' ORDER BY p.reference, p.id LIMIT :limit OFFSET :offset',
            ['company' => $company->getId()->toRfc4122(), 'limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map(static function (array $row): WatchItem {
            $review = new PriceReview(self::text($row['price_review_cost']), self::text($row['cost_price']), self::text($row['unit_price_net']));

            return new WatchItem(self::MARGIN_MOVED, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'previousCost' => $review->previousCost,
                'cost' => $review->cost,
                'price' => $review->price,
                'previousMargin' => $review->previousMargin(),
                'margin' => $review->margin(),
            ]);
        }, $rows), $total, $request);
    }

    /** A product merged away keeps no review of its own: its stock and its receipts are the product kept's now. */
    private static function rows(): string
    {
        return 'SELECT p.id, p.name, p.reference, p.price_review_cost, p.cost_price, p.unit_price_net
                  FROM product p
                 WHERE p.company_id = :company AND p.price_review_cost IS NOT NULL AND NOT ('.KeptRecords::mergedAway('p').')';
    }

    private static function known(string $kind): void
    {
        if (self::MARGIN_MOVED !== $kind) {
            throw new \LogicException(\sprintf('The price review watch has no %s.', $kind));
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
