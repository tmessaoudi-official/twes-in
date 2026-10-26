<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Http;

use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\NotDeliveredYet;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A delivered note printed again as its delivery left it, with delivery_note.read: who received and when, under
 * « Copie — livrée le … ». Never stored. Documented in DeliveryNotesOpenApi.
 */
#[AsController]
final readonly class DeliveryNoteDeliveredCopyController
{
    public function __construct(private PrintDeliveryNote $print, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/delivery-notes/{deliveryNoteId}/pdf/delivered', name: 'api_delivery_note_pdf_delivered', methods: ['GET'])]
    public function __invoke(string $companyId, string $deliveryNoteId): Response
    {
        $ids = ['companyId' => $companyId, 'deliveryNoteId' => $deliveryNoteId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), DeliveryNotePermission::READ);

        try {
            $printed = $this->print->deliveredCopy($company, CompanyPath::identifier($ids, 'deliveryNoteId'));
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (NotDeliveredYet $notYet) {
            throw new ConflictHttpException($notYet->getMessage(), $notYet);
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
