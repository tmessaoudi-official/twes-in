<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Http;

use App\Module\Invoices\Application\InvoiceCopy;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\NoCopyOfADraft;
use App\Module\Invoices\Application\PrintInvoice;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A duplicate or an up-to-date copy of an issued invoice, printed on request with invoice.read and never stored: the
 * original stays the one file issuing wrote. Documented in InvoicesOpenApi.
 */
#[AsController]
final readonly class InvoicePdfCopyController
{
    public function __construct(private PrintInvoice $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/invoices/{invoiceId}/pdf/{kind}', name: 'api_invoice_pdf_copy', methods: ['GET'])]
    public function __invoke(string $companyId, string $invoiceId, string $kind): Response
    {
        $copy = InvoiceCopy::tryFrom($kind) ?? throw new NotFoundHttpException('No such copy.');
        $ids = ['companyId' => $companyId, 'invoiceId' => $invoiceId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), InvoicePermission::READ);

        try {
            $printed = $this->print->copy($company, CompanyPath::identifier($ids, 'invoiceId'), $copy);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (NoCopyOfADraft $draft) {
            throw new ConflictHttpException('Only an issued invoice has a copy.', $draft);
        } catch (PdfRenderingFailed $failure) {
            throw new ServiceUnavailableHttpException(30, 'The PDF could not be rendered; try again shortly.', $failure);
        }

        return new Response($printed->contents, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $printed->fileName),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
