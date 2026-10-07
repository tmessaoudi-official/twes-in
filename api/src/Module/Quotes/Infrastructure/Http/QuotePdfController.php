<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Http;

use App\Module\Quotes\Application\PrintQuote;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Infrastructure\ApiPlatform\QuotePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A quote as a PDF, with quote.read. A plain controller rather than a resource: the answer is bytes, not JSON. The
 * module guard still applies, through this class's namespace. Documented in QuotesOpenApi.
 */
#[AsController]
final readonly class QuotePdfController
{
    public function __construct(private PrintQuote $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/quotes/{quoteId}/pdf', name: 'api_quote_pdf', methods: ['GET'])]
    public function __invoke(string $companyId, string $quoteId): Response
    {
        $ids = ['companyId' => $companyId, 'quoteId' => $quoteId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), QuotePermission::READ);

        try {
            $printed = $this->print->pdf($company, CompanyPath::identifier($ids, 'quoteId'));
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
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
