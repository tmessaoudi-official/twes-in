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
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockLossAttachmentResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\ReceivedFile;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    #[Route('/api/companies/{companyId}/stock-movements/{movementId}/attachments', name: 'api_stock_loss_attachment_upload', methods: ['POST'], defaults: ['_api_respond' => true])]
    public function upload(Request $request, string $companyId, string $movementId): JsonResponse
    {
        $ids = ['companyId' => $companyId, 'movementId' => $movementId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), StockPermission::WRITE);

        $file = ReceivedFile::of($request);

        try {
            $attachment = $this->attachments->attach($company, CompanyPath::identifier($ids, 'movementId'), $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (StockMovementNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (AttachmentRefused $refused) {
            throw DomainRule::refused('file', $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return new JsonResponse(StockLossAttachmentResource::of($attachment)->toArray(), Response::HTTP_CREATED);
    }

    /** Puts back a file taken off, where it was: what « Annuler » after taking it off sends. */
    #[Route('/api/companies/{companyId}/stock-movements/{movementId}/attachments/{attachmentId}/restore', name: 'api_stock_loss_attachment_restore', methods: ['POST'], defaults: ['_api_respond' => true])]
    public function restore(string $companyId, string $movementId, string $attachmentId): Response
    {
        $ids = ['companyId' => $companyId, 'movementId' => $movementId, 'attachmentId' => $attachmentId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), StockPermission::WRITE);

        try {
            $this->attachments->restore($company, CompanyPath::identifier($ids, 'movementId'), CompanyPath::identifier($ids, 'attachmentId'), $this->guard->account()->getId());
        } catch (StockMovementNotFound|StockLossAttachmentNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (AttachmentRefused $refused) {
            // Said on the whole request: a restore sends no file.
            throw DomainRule::refused(DomainRule::WHOLE_REQUEST, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
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
