<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Infrastructure\ApiPlatform\DocumentPreview;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * What a new invoice, or a draft as it is being edited, would come to, kept nowhere. It asks what saving would ask, so
 * whoever may not save it is not told its figures either, and it refuses what saving would refuse, on the same field.
 *
 * @implements ProcessorInterface<InvoiceResource, DocumentPreview>
 */
final readonly class PreviewInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private ManageInvoices $manage, private CompanyGuard $guard, private CreditNoteRight $creditNotes, private CurrencyScales $scales)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentPreview
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        $id = isset($uriVariables['invoiceId']) ? CompanyPath::identifier($uriVariables, 'invoiceId') : null;
        if (null !== $id) {
            $this->creditNotes->check($company, $id);
        }

        try {
            $totals = $this->manage->preview($company, $data->input(), $id);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceNotDraft $fixed) {
            throw new ConflictHttpException($fixed->getMessage(), $fixed);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return DocumentPreview::of($totals, $this->scales->of($company->getCurrency()));
    }
}
