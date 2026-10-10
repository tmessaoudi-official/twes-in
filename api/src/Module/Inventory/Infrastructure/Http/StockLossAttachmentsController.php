<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Http;

use App\Files\Application\AttachmentRefused;
use App\Module\Inventory\Application\KeepLossAttachments;
use App\Module\Inventory\Application\StockLossAttachmentNotFound;
use App\Module\Inventory\Application\StockMovementNotFound;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockLossAttachmentResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A file attached to a loss, sent as a multipart part named `file` (stock.write) and given back as its bytes
 * (stock.read). Plain controllers rather than resources: bytes, not JSON. The module guard still applies, through
 * this class's namespace. Documented in StockLossAttachmentsOpenApi.
 */
#[AsController]
final readonly class StockLossAttachmentsController
{
    public function __construct(private KeepLossAttachments $attachments, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/stock-movements/{movementId}/attachments', name: 'api_stock_loss_attachment_upload', methods: ['POST'])]
    public function upload(Request $request, string $companyId, string $movementId): JsonResponse
    {
        $ids = ['companyId' => $companyId, 'movementId' => $movementId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), StockPermission::WRITE);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('file: Send the file as a multipart part named file.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException('file: '.$file->getErrorMessage());
        }

        try {
            $attachment = $this->attachments->attach($company, CompanyPath::identifier($ids, 'movementId'), $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (StockMovementNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (AttachmentRefused $refused) {
            throw new UnprocessableEntityHttpException('file: '.$refused->getMessage(), $refused);
        }

        return new JsonResponse(StockLossAttachmentResource::of($attachment)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/companies/{companyId}/stock-movements/{movementId}/attachments/{attachmentId}/content', name: 'api_stock_loss_attachment_content', methods: ['GET'])]
    public function content(string $companyId, string $movementId, string $attachmentId): Response
    {
        $ids = ['companyId' => $companyId, 'movementId' => $movementId, 'attachmentId' => $attachmentId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), StockPermission::READ);

        try {
            [$attachment, $contents] = $this->attachments->attachmentContents($company, CompanyPath::identifier($ids, 'movementId'), CompanyPath::identifier($ids, 'attachmentId'));
        } catch (StockMovementNotFound|StockLossAttachmentNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
        $name = $attachment->getFile()->getOriginalName();

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $attachment->getFile()->getMime(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, (string) preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', $name)),
            'X-Content-Type-Options' => 'nosniff',
            // What a person uploaded is shown, never run: no script, no request, no form from it.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
