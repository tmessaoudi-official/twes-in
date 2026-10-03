<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Application\CreditExcessTo;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoiceNumberTaken;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Issues a draft. What the company's numbering cannot give (no series, a day before its last month, a number another
 * establishment already gave) is a 409, like a document that is no longer a draft; what the draft lacks is a 422.
 *
 * @implements ProcessorInterface<mixed, InvoiceResource>
 */
final readonly class IssueInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceWorkflow $workflow, private InvoiceTotals $totals, private CompanyGuard $guard, private InvoiceRepository $invoices)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::ISSUE);

        // A credit note reverses revenue, so issuing one takes the permission to draft one; the answer is the one a stranger gets.
        $draft = $this->invoices->ofIdInCompany(CompanyPath::identifier($uriVariables, 'invoiceId'), $company->getId());
        if (InvoiceType::CreditNote === $draft?->getType() && !$this->guard->may($company, InvoicePermission::CREDIT)) {
            throw new NotFoundHttpException('No such invoice.');
        }

        $request = $context['request'] ?? null;
        $asked = $request instanceof Request ? $request->query->get('excessTo') : null;
        $excessTo = \is_string($asked) ? CreditExcessTo::tryFrom($asked) : null;
        if (null !== $asked && null === $excessTo) {
            throw new UnprocessableEntityHttpException('excessTo: One of balance or refund.');
        }

        try {
            $invoice = $this->workflow->issue($company, CompanyPath::identifier($uriVariables, 'invoiceId'), $this->guard->account()->getId(), $excessTo);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceNotDraft|NoNumberingSeries|InvalidNumbering|InvoiceNumberTaken $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return InvoiceResource::of($invoice, $this->totals->figures($invoice), $this->guard->may($company, ProductPermission::COST_READ));
    }
}
