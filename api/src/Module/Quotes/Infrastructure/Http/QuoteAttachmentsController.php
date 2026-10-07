<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Http;

use App\Files\Application\AttachmentRefused;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteAttachmentNotFound;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Infrastructure\ApiPlatform\QuoteAttachmentResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\QuotePermission;
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
 * A file attached to a quote, sent as a multipart part named `file` (quote.write) and given back as its bytes
 * (quote.read). Plain controllers rather than resources: bytes, not JSON. The module guard still applies, through this
 * class's namespace. Documented in QuotesOpenApi.
 */
#[AsController]
final readonly class QuoteAttachmentsController
{
    public function __construct(private ManageQuotes $manage, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/quotes/{quoteId}/attachments', name: 'api_quote_attachment_upload', methods: ['POST'])]
    public function upload(Request $request, string $companyId, string $quoteId): JsonResponse
    {
        $ids = ['companyId' => $companyId, 'quoteId' => $quoteId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), QuotePermission::WRITE);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('file: Send the file as a multipart part named file.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException('file: '.$file->getErrorMessage());
        }

        try {
            $attachment = $this->manage->attach($company, CompanyPath::identifier($ids, 'quoteId'), $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (AttachmentRefused $refused) {
            throw new UnprocessableEntityHttpException('file: '.$refused->getMessage(), $refused);
        }

        return new JsonResponse(QuoteAttachmentResource::of($attachment)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/companies/{companyId}/quotes/{quoteId}/attachments/{attachmentId}/content', name: 'api_quote_attachment_content', methods: ['GET'])]
    public function content(string $companyId, string $quoteId, string $attachmentId): Response
    {
        $ids = ['companyId' => $companyId, 'quoteId' => $quoteId, 'attachmentId' => $attachmentId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), QuotePermission::READ);

        try {
            [$attachment, $contents] = $this->manage->attachmentContents($company, CompanyPath::identifier($ids, 'quoteId'), CompanyPath::identifier($ids, 'attachmentId'));
        } catch (QuoteNotFound|QuoteAttachmentNotFound $absent) {
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
