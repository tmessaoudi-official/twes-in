<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Http;

use App\Files\Application\AttachmentRefused;
use App\Module\Expenses\Application\AttachmentNotFound;
use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpenseAttachmentResource;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpensePermission;
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
 * A file attached to an expense, sent as a multipart part named `file` (expense.write) and given back as its bytes
 * (expense.read). Plain controllers rather than resources: bytes, not JSON. The module guard still applies, through
 * this class's namespace. Documented in ExpensesOpenApi.
 */
#[AsController]
final readonly class ExpenseAttachmentsController
{
    public function __construct(private ManageExpenses $manage, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/expenses/{expenseId}/attachments', name: 'api_expense_attachment_upload', methods: ['POST'])]
    public function upload(Request $request, string $companyId, string $expenseId): JsonResponse
    {
        $ids = ['companyId' => $companyId, 'expenseId' => $expenseId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ExpensePermission::WRITE);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('file: Send the file as a multipart part named file.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException('file: '.$file->getErrorMessage());
        }

        try {
            $attachment = $this->manage->attach($company, CompanyPath::identifier($ids, 'expenseId'), $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (ExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (AttachmentRefused $refused) {
            throw new UnprocessableEntityHttpException('file: '.$refused->getMessage(), $refused);
        }

        return new JsonResponse(ExpenseAttachmentResource::of($attachment)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/companies/{companyId}/expenses/{expenseId}/attachments/{attachmentId}/content', name: 'api_expense_attachment_content', methods: ['GET'])]
    public function content(string $companyId, string $expenseId, string $attachmentId): Response
    {
        $ids = ['companyId' => $companyId, 'expenseId' => $expenseId, 'attachmentId' => $attachmentId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ExpensePermission::READ);

        try {
            [$attachment, $contents] = $this->manage->attachmentContents($company, CompanyPath::identifier($ids, 'expenseId'), CompanyPath::identifier($ids, 'attachmentId'));
        } catch (ExpenseNotFound|AttachmentNotFound $absent) {
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
