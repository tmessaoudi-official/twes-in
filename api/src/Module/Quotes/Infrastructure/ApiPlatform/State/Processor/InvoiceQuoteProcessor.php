<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Quotes\Application\QuoteInvoicingRefused;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Application\QuoteTargetInvoiceNotDraft;
use App\Module\Quotes\Application\QuoteTargetInvoiceNotFound;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\Exception\QuoteTransitionRefused;
use App\Module\Quotes\Infrastructure\ApiPlatform\Output\QuoteView;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * « Facturer »: an accepted quote drafted wholly into a new invoice, or added to an open draft of its customer named
 * in the path (.../invoices/{invoiceId}), which the answer names (`invoiceId`). It writes an invoice, so it asks
 * invoice.write as well (403 without it), and the invoices module on: quoting needs only customers, as delivery notes do.
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

        $quoteId = CompanyPath::identifier($uriVariables, 'quoteId');
        $actor = $this->guard->account()->getId();
        try {
            $quote = isset($uriVariables['invoiceId'])
                ? $this->workflow->addToInvoice($company, $quoteId, CompanyPath::identifier($uriVariables, 'invoiceId'), $actor)
                : $this->workflow->invoice($company, $quoteId, $actor);
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteTargetInvoiceNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (QuoteTransitionRefused|QuoteTargetInvoiceNotDraft $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (QuoteInvoicingRefused $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return $this->view->of($quote);
    }
}
