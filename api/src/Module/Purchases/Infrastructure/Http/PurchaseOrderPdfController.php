<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Http;

use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Purchases\Application\PrintPurchaseOrder;
use App\Module\Purchases\Application\PurchaseOrderNotFound;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An order to a vendor as a PDF, with purchase.read and product.cost.read: the prices it prints are costs, which nobody
 * else reads, so whoever may not read them is answered as if there were no such order. A plain controller rather than a
 * resource, as the answer is bytes; the module guard still applies through this class's namespace. Documented in
 * PurchasesOpenApi.
 */
#[AsController]
final readonly class PurchaseOrderPdfController
{
    public function __construct(private PrintPurchaseOrder $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/purchase-orders/{purchaseOrderId}/pdf', name: 'api_purchase_order_pdf', methods: ['GET'])]
    public function __invoke(string $companyId, string $purchaseOrderId): Response
    {
        $ids = ['companyId' => $companyId, 'purchaseOrderId' => $purchaseOrderId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), PurchaseOrderPermission::READ);
        if (!$this->guard->may($company, ProductPermission::COST_READ)) {
            throw new NotFoundHttpException('No such purchase order.');
        }

        try {
            $printed = $this->print->pdf($company, CompanyPath::identifier($ids, 'purchaseOrderId'));
        } catch (PurchaseOrderNotFound $absent) {
            throw new NotFoundHttpException('No such purchase order.', $absent);
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
