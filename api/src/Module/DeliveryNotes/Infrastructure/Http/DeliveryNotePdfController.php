<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Http;

use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\DeliveryNotePermission;
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
 * A delivery note as a PDF, with delivery_note.read. A plain controller rather than a resource: the answer is bytes,
 * not JSON. The module guard still applies, through this class's namespace. Documented in DeliveryNotesOpenApi.
 */
#[AsController]
final readonly class DeliveryNotePdfController
{
    public function __construct(private PrintDeliveryNote $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/delivery-notes/{deliveryNoteId}/pdf', name: 'api_delivery_note_pdf', methods: ['GET'])]
    public function __invoke(string $companyId, string $deliveryNoteId): Response
    {
        $ids = ['companyId' => $companyId, 'deliveryNoteId' => $deliveryNoteId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), DeliveryNotePermission::READ);

        try {
            $printed = $this->print->pdf($company, CompanyPath::identifier($ids, 'deliveryNoteId'));
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
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
