<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Http;

use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ProductPhotoNotFound;
use App\Module\Products\Application\ProductPhotoRefused;
use App\Module\Products\Application\ProductPhotos;
use App\Module\Products\Domain\PhotoSize;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPhotoResource;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A product's photo sent as a multipart part named `file` (product.write), its pictures given back as bytes
 * (product.read), the main one's copies to the customer screen (product.read), and a removed one put back
 * (product.write). Plain controllers rather than resources: bytes, and a
 * refusal whose code the screen translates. The module guard still applies, through this class's namespace.
 * Documented in ProductPhotosOpenApi.
 */
#[AsController]
final readonly class ProductPhotosController
{
    public function __construct(private ProductPhotos $photos, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/products/{productId}/photos', name: 'api_product_photo_upload', methods: ['POST'])]
    public function upload(Request $request, string $companyId, string $productId): JsonResponse
    {
        $ids = ['companyId' => $companyId, 'productId' => $productId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ProductPermission::WRITE);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            // A part over PHP's own upload limit arrives as an invalid file: to the person, the photo was too large.
            $tooLarge = $file instanceof UploadedFile && \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true);

            return self::refused($tooLarge
                ? new ProductPhotoRefused($file->getErrorMessage(), ProductPhotoRefused::TOO_LARGE)
                : new ProductPhotoRefused('Send the photo as a multipart part named file.', ProductPhotoRefused::EMPTY));
        }

        try {
            $photo = $this->photos->add($company, CompanyPath::identifier($ids, 'productId'), $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()), $this->guard->account()->getId());
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ProductPhotoRefused $refused) {
            return self::refused($refused);
        }

        return new JsonResponse(ProductPhotoResource::of($photo)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/companies/{companyId}/products/{productId}/photos/{photoId}/restore', name: 'api_product_photo_restore', methods: ['POST'])]
    public function restore(string $companyId, string $productId, string $photoId): Response
    {
        $ids = ['companyId' => $companyId, 'productId' => $productId, 'photoId' => $photoId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ProductPermission::WRITE);

        try {
            $this->photos->restore($company, CompanyPath::identifier($ids, 'productId'), CompanyPath::identifier($ids, 'photoId'), $this->guard->account()->getId());
        } catch (ProductNotFound|ProductPhotoNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ProductPhotoRefused $refused) {
            return self::refused($refused);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/companies/{companyId}/products/{productId}/photos/{photoId}/content', name: 'api_product_photo_content', methods: ['GET'])]
    public function content(Request $request, string $companyId, string $productId, string $photoId): Response
    {
        $ids = ['companyId' => $companyId, 'productId' => $productId, 'photoId' => $photoId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ProductPermission::READ);
        $size = PhotoSize::tryFrom($request->query->getString('size', PhotoSize::Large->value))
            ?? throw new NotFoundHttpException('A photo comes as small, large or original.');

        try {
            [$mime, $contents] = $this->photos->contents($company, CompanyPath::identifier($ids, 'productId'), CompanyPath::identifier($ids, 'photoId'), $size);
        } catch (ProductNotFound|ProductPhotoNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return self::picture($mime, $contents);
    }

    /**
     * The customer screen's way to the main photo: under its own prefix, the one the screen's hold lets through, and
     * only the main photo's copies, so a screen turned to a customer never reaches the original nor the rest of the
     * gallery.
     */
    #[Route('/api/companies/{companyId}/customer-screen/products/{productId}/photos/{photoId}', name: 'api_customer_screen_product_photo', methods: ['GET'])]
    public function screenPhoto(Request $request, string $companyId, string $productId, string $photoId): Response
    {
        $ids = ['companyId' => $companyId, 'productId' => $productId, 'photoId' => $photoId];
        $company = $this->guard->companyForActing(CompanyPath::identifier($ids, 'companyId'), ProductPermission::READ);
        $size = PhotoSize::tryFrom($request->query->getString('size', PhotoSize::Large->value))
            ?? throw new NotFoundHttpException('The screen shows a photo small or large.');

        try {
            [$mime, $contents] = $this->photos->mainCopy($company, CompanyPath::identifier($ids, 'productId'), CompanyPath::identifier($ids, 'photoId'), $size);
        } catch (ProductNotFound|ProductPhotoNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return self::picture($mime, $contents);
    }

    private static function picture(string $mime, string $contents): Response
    {
        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            // What a person uploaded is shown, never run: no script, no request, no form from it.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            // A photo's pictures never change under its id, and a list shows many at once: the browser keeps them.
            // Private, since only who may read the product may see them.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    private static function refused(ProductPhotoRefused $refused): JsonResponse
    {
        return new JsonResponse(['code' => $refused->reason, 'params' => (object) $refused->params, 'message' => $refused->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
