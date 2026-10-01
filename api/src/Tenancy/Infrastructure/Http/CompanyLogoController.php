<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Files\Application\AttachmentRefused;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyProfileResource;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A company's logo, sent as a multipart part named `file` (company.settings), given back as its bytes to any member
 * (company.read) and removed (company.settings). Plain controllers rather than resources: bytes, not JSON. Documented
 * in CompanyLogoOpenApi.
 */
#[AsController]
final readonly class CompanyLogoController
{
    public function __construct(private CompanyLogo $logo, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/logo', name: 'api_company_logo_upload', methods: ['POST'])]
    public function upload(Request $request, string $companyId): JsonResponse
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('file: Send the file as a multipart part named file.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException('file: '.$file->getErrorMessage());
        }

        try {
            $attachment = $this->logo->set($company, $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (AttachmentRefused $refused) {
            throw new UnprocessableEntityHttpException('file: '.$refused->getMessage(), $refused);
        }

        return new JsonResponse(['logoVersion' => $attachment->getFile()->getId()->toRfc4122()], Response::HTTP_CREATED);
    }

    #[Route('/api/companies/{companyId}/logo', name: 'api_company_logo_content', methods: ['GET'])]
    public function content(string $companyId): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), CompanyProfileResource::READ_PERMISSION);

        $current = $this->logo->current($company);
        $contents = $this->logo->contentsOf($company);
        if (null === $current || null === $contents) {
            throw new NotFoundHttpException('This company has no logo.');
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $current->getFile()->getMime(),
            'X-Content-Type-Options' => 'nosniff',
            // A picture is shown, never run: no script, no request, no form from it.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            // The screen asks for it by the version the profile names, so a changed logo is a new address.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    #[Route('/api/companies/{companyId}/logo', name: 'api_company_logo_remove', methods: ['DELETE'])]
    public function remove(string $companyId): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        $this->logo->remove($company, $this->guard->account()->getId());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
