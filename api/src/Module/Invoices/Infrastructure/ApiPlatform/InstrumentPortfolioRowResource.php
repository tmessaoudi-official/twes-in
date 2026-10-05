<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Invoices\Domain\PaymentInstrument;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One cheque or traite of the company's portfolio, with the invoice it was received against and that invoice's
 * customer (docs/SPEC.md § 7, 2026-09-21 18:40), read with invoice.read, a page at a time. Receiving, depositing and
 * cashing stay on the invoice, where the money is owed.
 */
#[ApiResource(
    shortName: 'InstrumentPortfolioRow',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/instruments',
            provider: InstrumentPortfolioProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
            parameters: [
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['open', 'held', 'deposited', 'cashed', 'unpaid']]], description: 'Several statuses, OR\'d; a single `status=held` still works. `open` is what still promises money, held and deposited together; left out, every status is listed.', constraints: []),
                'kind[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['check', 'draft']]], description: 'Cheques, traites or both, OR\'d.', constraints: []),
                'customerId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], description: 'The customers of the invoices the instruments were handed over for, OR\'d.', constraints: []),
                'dueOn[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Due on or after this day.'),
                'dueOn[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Due on or before this day.'),
                'amount[min]' => new QueryParameter(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,4})?$'], description: 'An instrument of at least this amount.'),
                'amount[max]' => new QueryParameter(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,4})?$'], description: 'An instrument of at most this amount.'),
                'order[dueOn]' => new QueryParameter(schema: self::DIRECTION),
                'order[amount]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
    ],
)]
final class InstrumentPortfolioRowResource
{
    public const string READ = 'instrument_portfolio_row:read';

    /** Which way one of the list's sorts reads. */
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[ApiProperty(schema: ['type' => 'string', 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public string $invoiceId = '';

    /** The invoice's number; null for none, which an issued invoice never is. */
    #[Groups([self::READ])]
    public ?string $invoiceNumber = null;

    #[Groups([self::READ])]
    public string $customerName = '';

    /** The company's currency, which the amount is in. */
    #[Groups([self::READ])]
    public string $currency = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['check', 'draft']])]
    #[Groups([self::READ])]
    public string $kind = '';

    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '250.500'])]
    #[Groups([self::READ])]
    public string $amount = '';

    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Groups([self::READ])]
    public string $dueOn = '';

    #[Groups([self::READ])]
    public ?string $bank = null;

    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['held', 'deposited', 'cashed', 'unpaid']])]
    #[Groups([self::READ])]
    public string $status = '';

    /** The day it was cashed or came back unpaid; null while it is open. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $settledOn = null;

    public static function of(PaymentInstrument $instrument): self
    {
        $invoice = $instrument->getInvoice();
        $row = new self();
        $row->id = $instrument->getId()->toRfc4122();
        $row->invoiceId = $invoice->getId()->toRfc4122();
        $row->invoiceNumber = $invoice->getNumber();
        $row->customerName = $invoice->getCustomer()->getProfile()->name;
        $row->currency = $instrument->getCompany()->getCurrency();
        $row->kind = $instrument->getKind()->value;
        $row->amount = $instrument->getAmount();
        $row->dueOn = $instrument->getDueOn()->format('Y-m-d');
        $row->bank = $instrument->getBank();
        $row->number = $instrument->getNumber();
        $row->status = $instrument->getStatus()->value;
        $row->settledOn = $instrument->getSettledOn()?->format('Y-m-d');

        return $row;
    }
}
