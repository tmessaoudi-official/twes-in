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
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the invoices put on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): each customer with invoices late
 * past the threshold, and how many goods have not been sold for as long. Late means what the overdue filter of the
 * invoices list means, so the link from here shows the same invoices.
 */
final readonly class InvoiceWatch implements DeclaresWatch
{
    public const string LATE_CUSTOMER = 'invoices.late_customer';
    public const string UNSOLD_PRODUCTS = 'invoices.unsold_products';

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

    public function itemsFor(Company $company, \DateTimeImmutable $today): array
    {
        return [...$this->lateCustomers($company, $today), ...$this->unsoldProducts($company, $today)];
    }

    /** @return list<WatchItem> */
    private function lateCustomers(Company $company, \DateTimeImmutable $today): array
    {
        $days = $this->days($company, InvoiceWatchSettings::LATE_AFTER_DAYS);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.customer_id, c.name, COUNT(*) AS invoices, SUM(i.amount_due) AS amount, MIN(i.due_date) AS oldest
               FROM invoice i JOIN customer c ON c.id = i.customer_id
              WHERE i.company_id = :company AND i.document_type = :type AND i.status IN (:statuses)
                AND i.amount_due > 0 AND i.due_date < :cutoff
              GROUP BY i.customer_id, c.name
              ORDER BY MIN(i.due_date), c.name, i.customer_id',
            [
                'company' => $company->getId()->toRfc4122(),
                'type' => InvoiceType::Invoice->value,
                'statuses' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value],
                'cutoff' => $today->modify("-$days days")->format('Y-m-d'),
            ],
            ['statuses' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): WatchItem => new WatchItem(self::LATE_CUSTOMER, self::text($row['customer_id']), [
            'customer' => self::text($row['name']),
            'invoices' => (int) self::text($row['invoices']),
            'amount' => self::text($row['amount']),
            'currency' => $company->getCurrency(),
            'days' => (int) new \DateTimeImmutable(self::text($row['oldest']))->diff($today)->days,
        ]), $rows);
    }

    /**
     * Goods only: a service not sold is no stock sitting anywhere. A product younger than the threshold has not had
     * the time to be sold, and one switched off is not offered any more.
     *
     * @return list<WatchItem>
     */
    private function unsoldProducts(Company $company, \DateTimeImmutable $today): array
    {
        $days = $this->days($company, InvoiceWatchSettings::UNSOLD_AFTER_DAYS);
        $since = $today->modify("-$days days")->format('Y-m-d');
        $count = (int) self::text($this->connection->fetchOne(
            "SELECT COUNT(*) FROM product p
              WHERE p.company_id = :company AND p.is_active AND p.kind = 'goods' AND p.created_at < :since
                AND NOT EXISTS (
                    SELECT 1 FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id
                     WHERE l.product_id = p.id AND i.document_type = :type AND i.status IN (:sold) AND i.issue_date >= :since
                )",
            [
                'company' => $company->getId()->toRfc4122(),
                'since' => $since,
                'type' => InvoiceType::Invoice->value,
                'sold' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value],
            ],
            ['sold' => ArrayParameterType::STRING],
        ));

        return 0 === $count ? [] : [new WatchItem(self::UNSOLD_PRODUCTS, null, ['products' => $count, 'days' => $days])];
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
