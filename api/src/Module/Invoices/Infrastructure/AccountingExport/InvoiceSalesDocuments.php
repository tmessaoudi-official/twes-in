<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\AccountingExport;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\AccountingExport\Application\DocumentTax;
use App\Module\AccountingExport\Application\JournalPeriod;
use App\Module\AccountingExport\Application\SalesDocument;
use App\Module\AccountingExport\Application\SalesDocuments;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The sales journal's documents: every invoice and credit note the company issued in the period, with the figures
 * issuing froze and the customer as it was printed. A draft has no issue day, so it is never among them.
 */
final readonly class InvoiceSalesDocuments implements SalesDocuments
{
    private const int BATCH = 200;

    public function __construct(private EntityManagerInterface $entityManager, private InvoiceTotals $totals, private CurrencyScales $scales)
    {
    }

    public function issuedIn(Company $company, JournalPeriod $period): iterable
    {
        $scale = $this->scales->of($company->getCurrency());
        for ($offset = 0;; $offset += self::BATCH) {
            $found = $this->entityManager->createQueryBuilder()
                ->select('i')->from(Invoice::class, 'i')
                ->where('i.company = :company')->andWhere('i.issueDate BETWEEN :from AND :to')
                // Only what was issued is in the books: a draft, or a draft withdrawn, never is.
                ->andWhere('i.status NOT IN (:unissued)')
                ->setParameter('company', $company->getId(), 'uuid')
                ->setParameter('unissued', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
                ->setParameter('from', $period->from->format('Y-m-d'))->setParameter('to', $period->to->format('Y-m-d'))
                ->orderBy('i.issueDate')->addOrderBy('i.number')->addOrderBy('i.id')
                ->setFirstResult($offset)->setMaxResults(self::BATCH)
                ->getQuery()->getResult();
            $invoices = array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $row): bool => $row instanceof Invoice));
            foreach ($invoices as $invoice) {
                yield $this->document($invoice, $scale);
            }
            if (\count($invoices) < self::BATCH) {
                return;
            }
        }
    }

    private function document(Invoice $invoice, int $scale): SalesDocument
    {
        $figures = $this->totals->figures($invoice);
        // A credit note's figures are negative already: the journal sums them as they are.
        $atScale = static fn (string $amount): string => Decimal::format(Decimal::of($amount), $scale);
        $taxes = [];
        foreach ($figures->taxes as $tax) {
            $taxes[] = new DocumentTax($tax['code'], $tax['rate'], $atScale($tax['base']), $atScale($tax['amount']));
        }
        foreach ($figures->fixedTaxes as $tax) {
            $taxes[] = new DocumentTax($tax['code'], null, null, $atScale($tax['amount']));
        }
        $snapshot = $invoice->getCustomerSnapshot();
        $customer = $invoice->getCustomer();
        $identifiers = $snapshot->identifiers ?? $customer->getProfile()->identifiers;
        ksort($identifiers);

        return new SalesDocument(
            $invoice->getIssueDate() ?? throw new \LogicException('An issued document has its day.'),
            $invoice->getNumber() ?? '',
            $invoice->getType()->value,
            $snapshot->number ?? $customer->getNumber(),
            $snapshot->name ?? $customer->getProfile()->name,
            implode('; ', array_map(static fn (string $key, string $value): string => "$key=$value", array_keys($identifiers), $identifiers)),
            $taxes,
            $atScale($figures->totalNet),
            $atScale($figures->total),
        );
    }
}
