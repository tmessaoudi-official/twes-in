<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Watch;

use App\Module\Invoices\Application\InvoiceWatchSettings;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the invoices put on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): each customer with invoices late
 * past the threshold, and each good that has not been sold for as long. Late means what the overdue filter of the
 * invoices list means, so the link from here shows the same invoices.
 */
final readonly class InvoiceWatch implements DeclaresWatch
{
    public const string LATE_CUSTOMER = 'invoices.late_customer';
    public const string UNSOLD_PRODUCTS = 'invoices.unsold_products';

    /** What late means, shared by the count and the rows so the number on the home is the number of rows behind it. */
    public const string LATE_WHERE = 'i.company_id = :company AND i.document_type = :type AND i.status IN (:statuses) AND i.amount_due > 0 AND i.due_date < :cutoff';

    public function __construct(private Connection $connection, private ReadSetting $settings)
    {
    }

    public function key(): string
    {
        return 'invoices';
    }

    public function module(): string
    {
        return InvoicesModule::KEY;
    }

    public function permission(): string
    {
        return InvoicePermission::READ;
    }

    public function kinds(): array
    {
        return [self::LATE_CUSTOMER, self::UNSOLD_PRODUCTS];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        [$sql, $params, $types] = match ($kind) {
            // The customers alone: the join to their names is the page's business, and counting without it reads an
            // index of the open invoices and nothing else.
            self::LATE_CUSTOMER => ['SELECT COUNT(*) FROM (SELECT 1 FROM invoice i WHERE '.self::LATE_WHERE.' GROUP BY i.customer_id) late', ...$this->lateBindings($company, $today)],
            self::UNSOLD_PRODUCTS => ['SELECT COUNT(*) FROM ('.$this->unsoldFrom().') unsold', ...$this->unsoldBindings($company, $today)],
            default => throw new \LogicException(\sprintf('The invoices watch has no %s.', $kind)),
        };

        return (int) self::text($this->connection->fetchOne($sql, $params, $types));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $paging = ['limit' => $request->size, 'offset' => $request->offset()];

        return match ($kind) {
            self::LATE_CUSTOMER => $this->lateCustomers($company, $today, $paging, $total, $request),
            self::UNSOLD_PRODUCTS => $this->unsoldProducts($company, $today, $paging, $total, $request),
            default => throw new \LogicException(\sprintf('The invoices watch has no %s.', $kind)),
        };
    }

    /**
     * @param array{limit: int, offset: int} $paging
     *
     * @return Page<WatchItem>
     */
    private function lateCustomers(Company $company, \DateTimeImmutable $today, array $paging, int $total, PageRequest $request): Page
    {
        [$params, $types] = $this->lateBindings($company, $today);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.customer_id, c.name, COUNT(*) AS invoices, SUM(i.amount_due) AS amount, MIN(i.due_date) AS oldest
               FROM invoice i JOIN customer c ON c.id = i.customer_id
              WHERE '.self::LATE_WHERE.'
              GROUP BY i.customer_id, c.name
              ORDER BY MIN(i.due_date), c.name, i.customer_id
              LIMIT :limit OFFSET :offset',
            $params + $paging,
            $types,
        );

        return new Page(array_map(static fn (array $row): WatchItem => new WatchItem(self::LATE_CUSTOMER, self::text($row['customer_id']), [
            'customer' => self::text($row['name']),
            'invoices' => (int) self::text($row['invoices']),
            'amount' => self::text($row['amount']),
            'currency' => $company->getCurrency(),
            'days' => (int) new \DateTimeImmutable(self::text($row['oldest']))->diff($today)->days,
        ]), $rows), $total, $request);
    }

    /**
     * Goods only: a service not sold is no stock sitting anywhere. A product younger than the threshold has not had
     * the time to be sold, and one switched off is not offered any more. Each row says how long it has gone unsold, from
     * its last sale, or from the day it was created when it never sold. The rows are in reference order: the last sale of
     * every candidate is a read of its whole sales history, so it is worked out for the page's products alone.
     *
     * @param array{limit: int, offset: int} $paging
     *
     * @return Page<WatchItem>
     */
    private function unsoldProducts(Company $company, \DateTimeImmutable $today, array $paging, int $total, PageRequest $request): Page
    {
        [$params, $types] = $this->unsoldBindings($company, $today);
        $rows = $this->connection->fetchAllAssociative(
            $this->unsoldFrom().' ORDER BY p.reference, p.id LIMIT :limit OFFSET :offset',
            $params + $paging,
            $types,
        );
        $lastSold = [] === $rows ? [] : $this->connection->fetchAllKeyValue(
            'SELECT l.product_id, MAX(i.issue_date)
               FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id
              WHERE l.product_id IN (:products) AND i.document_type = :type AND i.status IN (:sold)
              GROUP BY l.product_id',
            ['products' => array_column($rows, 'id'), 'type' => $params['type'], 'sold' => $params['sold']],
            ['products' => ArrayParameterType::STRING, 'sold' => ArrayParameterType::STRING],
        );

        return new Page(array_map(static function (array $row) use ($lastSold, $today): WatchItem {
            $id = self::text($row['id']);
            $since = new \DateTimeImmutable(self::text($lastSold[$id] ?? $row['created']));

            return new WatchItem(self::UNSOLD_PRODUCTS, $id, [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'days' => (int) $since->diff($today)->days,
            ]);
        }, $rows), $total, $request);
    }

    /** @return array{array<string, mixed>, array<string, ArrayParameterType>} */
    private function lateBindings(Company $company, \DateTimeImmutable $today): array
    {
        $days = $this->days($company, InvoiceWatchSettings::LATE_AFTER_DAYS);

        return [
            [
                'company' => $company->getId()->toRfc4122(),
                'type' => InvoiceType::Invoice->value,
                'statuses' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value],
                'cutoff' => $today->modify("-$days days")->format('Y-m-d'),
            ],
            ['statuses' => ArrayParameterType::STRING],
        ];
    }

    /**
     * The products to list, unordered and unpaged: the count wraps it and the page orders and cuts it. Not sold since the
     * threshold is a `NOT EXISTS`, which stops at the first recent sale: a product that sells is answered in a few rows,
     * and one that does not has few rows to read.
     */
    private function unsoldFrom(): string
    {
        return "SELECT p.id, p.name, p.reference, CAST(p.created_at AS date) AS created
                  FROM product p
                 WHERE p.company_id = :company AND p.is_active AND p.kind = 'goods' AND p.created_at < :since
                   AND NOT EXISTS (
                       SELECT 1 FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id
                        WHERE l.product_id = p.id AND i.document_type = :type AND i.status IN (:sold) AND i.issue_date >= :since
                   )";
    }

    /** @return array{array<string, mixed>, array<string, ArrayParameterType>} */
    private function unsoldBindings(Company $company, \DateTimeImmutable $today): array
    {
        $days = $this->days($company, InvoiceWatchSettings::UNSOLD_AFTER_DAYS);

        return [
            [
                'company' => $company->getId()->toRfc4122(),
                'since' => $today->modify("-$days days")->format('Y-m-d'),
                'type' => InvoiceType::Invoice->value,
                'sold' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value],
            ],
            ['sold' => ArrayParameterType::STRING],
        ];
    }

    private function days(Company $company, string $key): int
    {
        $value = $this->settings->value(new SettingContext($company), $key);

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('%s is not an integer.', $key));
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
