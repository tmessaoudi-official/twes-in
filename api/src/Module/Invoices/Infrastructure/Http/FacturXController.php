<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Http;

use App\Module\Invoices\Application\FacturX\ExportFacturX;
use App\Module\Invoices\Application\FacturX\FacturXRefused;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * An issued invoice's or credit note's Factur-X, with invoice.read: the EN 16931 Cross Industry Invoice alone, or embedded
 * in its PDF as PDF/A-3. A plain controller rather than a resource: the answer is a file, or the reason there is none —
 * 409 while the document is a draft, 422 naming every datum it lacks. The module guard applies through this class's
 * namespace. Documented in InvoicesOpenApi.
 */
#[AsController]
final readonly class FacturXController
{
    public function __construct(private ExportFacturX $export, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/invoices/{invoiceId}/factur-x.xml', name: 'api_invoice_factur_x_xml', methods: ['GET'])]
    public function xml(string $companyId, string $invoiceId): Response
    {
        [$company, $id] = $this->acting($companyId, $invoiceId);
        try {
            $file = $this->export->xml($company, $id);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (FacturXRefused $refused) {
            return self::refusal($refused);
        }

        return new Response($file->contents, Response::HTTP_OK, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->fileName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/api/companies/{companyId}/invoices/{invoiceId}/factur-x.pdf', name: 'api_invoice_factur_x_pdf', methods: ['GET'])]
    public function pdf(string $companyId, string $invoiceId): Response
    {
        [$company, $id] = $this->acting($companyId, $invoiceId);
        try {
            $file = $this->export->pdf($company, $id);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (FacturXRefused $refused) {
            return self::refusal($refused);
        } catch (PdfRenderingFailed $failure) {
            throw new ServiceUnavailableHttpException(30, 'The Factur-X PDF could not be written; try again shortly.', $failure);
        }

        return new Response($file->contents, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file->fileName),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{0: Company, 1: Uuid} */
    private function acting(string $companyId, string $invoiceId): array
    {
        $ids = ['companyId' => $companyId, 'invoiceId' => $invoiceId];

        return [$this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), InvoicePermission::READ), CompanyPath::identifier($ids, 'invoiceId')];
    }

    private static function refusal(FacturXRefused $refused): JsonResponse
    {
        return new JsonResponse(
            ['code' => $refused->reason, 'params' => (object) $refused->params, 'message' => $refused->getMessage(), 'gaps' => array_map(static fn (array $gap): array => ['code' => $gap['code'], 'params' => (object) $gap['params']], $refused->gaps)],
            FacturXRefused::NOT_ISSUED === $refused->reason ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
