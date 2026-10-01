<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Http;

use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Invoices\Application\InvalidStatementPeriod;
use App\Module\Invoices\Application\PrintStatement;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A customer's statement of account as a PDF, over the same period the JSON statement takes. It shows a customer's
 * money, so it takes the right to see both the customer and the invoices. Documented in InvoicesOpenApi.
 */
#[AsController]
final readonly class StatementPdfController
{
    public function __construct(private PrintStatement $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/customers/{customerId}/statement/pdf', name: 'api_customer_statement_pdf', methods: ['GET'])]
    public function __invoke(Request $request, string $companyId, string $customerId): Response
    {
        $ids = ['companyId' => $companyId, 'customerId' => $customerId];
        $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), CustomerPermission::READ);
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), InvoicePermission::READ);

        try {
            $printed = $this->print->pdf(
                $company,
                CompanyPath::identifier($ids, 'customerId'),
                self::day($request, 'from'),
                self::day($request, 'to'),
            );
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        } catch (InvalidStatementPeriod $refused) {
            throw new UnprocessableEntityHttpException('to: '.$refused->getMessage(), $refused);
        } catch (PdfRenderingFailed $failure) {
            throw new ServiceUnavailableHttpException(30, 'The PDF could not be rendered; try again shortly.', $failure);
        }

        return new Response($printed->contents, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $printed->fileName),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function day(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new UnprocessableEntityHttpException($name.': A day is written YYYY-MM-DD.');
        }

        return $day;
    }
}
