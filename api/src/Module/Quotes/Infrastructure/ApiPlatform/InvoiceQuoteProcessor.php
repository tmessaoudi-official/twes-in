<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Quotes\Application\QuoteInvoicingRefused;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\QuoteTransitionRefused;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * « Facturer »: an accepted quote drafted wholly into a new invoice, which the answer names (`invoiceId`). It drafts an
 * invoice, so it asks invoice.write as well (403 without it), and the invoices module on: quoting needs only customers,
 * as delivery notes do.
 *
 * @implements ProcessorInterface<mixed, QuoteResource>
 */
final readonly class InvoiceQuoteProcessor implements ProcessorInterface
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteView $view, private CompanyGuard $guard, private ModuleStates $modules)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);
        if (!$this->guard->may($company, InvoicePermission::WRITE)) {
            throw new AccessDeniedHttpException('Invoicing a quote drafts an invoice: invoice.write is needed as well.');
        }
        // The module guard answered for quotes, whose resource this is; the invoice it drafts needs invoices on too.
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }

        try {
            $quote = $this->workflow->invoice($company, CompanyPath::identifier($uriVariables, 'quoteId'), $this->guard->account()->getId());
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (QuoteInvoicingRefused $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return $this->view->of($quote);
    }
}
