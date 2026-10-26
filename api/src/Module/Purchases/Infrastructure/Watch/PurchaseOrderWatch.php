<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Watch;

use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Module\Purchases\Infrastructure\Module\PurchasesModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The orders whose goods are due, for whoever receives them: the person who sent an order and the one at the door are
 * often two, and the second is told here rather than by the orders list. Due means sent, not wholly received, and the
 * day the goods were expected has come; an order naming no day is awaited without a date, which the list says. The
 * longest awaited first; receiving everything, or closing or cancelling the order, takes it off by itself.
 */
final readonly class PurchaseOrderWatch implements DeclaresWatch
{
    public const string TO_RECEIVE = 'purchase_order.to_receive';

    /** What due means, shared by the count and the rows so the number on the home is the number of rows behind it. */
    private const string DUE = 'po.company_id = :company AND po.status IN (:open) AND po.expected_on <= :today';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'purchase_order';
    }

    public function module(): string
    {
        return PurchasesModule::KEY;
    }

    public function permission(): string
    {
        return PurchaseOrderPermission::RECEIVE;
    }

    public function kinds(): array
    {
        return [self::TO_RECEIVE];
    }

    public function changedBy(): array
    {
        return ['purchase_order'];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);
        [$params, $types] = self::bindings($company, $today);

        return Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM purchase_order po WHERE '.self::DUE, $params, $types));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        [$params, $types] = self::bindings($company, $today);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT po.id, po.number, po.vendor_name, po.expected_on
               FROM purchase_order po
              WHERE '.self::DUE.'
              ORDER BY po.expected_on, po.id
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $request->size, 'offset' => $request->offset()],
            $types,
        );

        return new Page(array_map(static function (array $row) use ($today): WatchItem {
            $expected = Rows::text($row['expected_on']);

            return new WatchItem(self::TO_RECEIVE, Rows::text($row['id']), [
                'number' => Rows::text($row['number']),
                'vendor' => Rows::text($row['vendor_name']),
                'expectedOn' => $expected,
                'days' => (int) new \DateTimeImmutable($expected)->diff($today)->days,
            ]);
        }, $rows), $total, $request);
    }

    /** @return array{array<string, mixed>, array<string, ArrayParameterType>} */
    private static function bindings(Company $company, \DateTimeImmutable $today): array
    {
        return [
            [
                'company' => $company->getId()->toRfc4122(),
                'open' => [PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartlyReceived->value],
                'today' => $today->format('Y-m-d'),
            ],
            ['open' => ArrayParameterType::STRING],
        ];
    }

    private static function known(string $kind): void
    {
        if (self::TO_RECEIVE !== $kind) {
            throw new \LogicException(\sprintf('The purchases watch has no %s.', $kind));
        }
    }
}
