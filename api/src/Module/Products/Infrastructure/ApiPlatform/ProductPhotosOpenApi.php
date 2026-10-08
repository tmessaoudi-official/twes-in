<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Module\Products\Application\ProductPhotoRefused;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents what ProductPhotosController answers, which API Platform does not describe: bytes and coded refusals. */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ProductPhotosOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);
        $refused = new Response(
            'The photo is not kept, and why: a stable code, translated by the screen, with its parameters',
            new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
                'type' => 'object',
                'required' => ['code', 'params', 'message'],
                'properties' => [
                    'code' => ['type' => 'string', 'enum' => ProductPhotoRefused::REASONS],
                    'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'too_large: maxBytes; too_many_pixels: maxMegapixels; too_many_photos: max.'],
                    'message' => ['type' => 'string'],
                ],
            ]))]),
        );
        $paths = $openApi->getPaths();

        $collection = '/api/companies/{companyId}/products/{productId}/photos';
        $listed = $paths->getPath($collection) ?? new PathItem();
        $paths->addPath($collection, $listed->withPost(new Operation(
            operationId: 'addProductPhoto',
            tags: ['ProductPhoto'],
            responses: [
                '201' => new Response('The photo', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/ProductPhoto-product_photo.read']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such product in this company, no product.write, or the module is switched off'),
                '422' => $refused,
            ],
            summary: 'Adds a photo to a product',
            description: 'JPEG, PNG or WebP, read from the bytes; at most 6 photos a product, 5 MB and 16 megapixels each by default. The first photo of a product is its main one. The server keeps the photo as sent and cuts a small and a large copy.',
            parameters: [$uuid('companyId', 'The company'), $uuid('productId', 'The product')],
            requestBody: new RequestBody(
                'The picture, as a multipart part named file',
                new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]))]),
                true,
            ),
        )));

        $paths->addPath($collection.'/{photoId}/restore', new PathItem(post: new Operation(
            operationId: 'restoreProductPhoto',
            tags: ['ProductPhoto'],
            responses: [
                '204' => new Response('Back in the gallery where it was, main again if it was'),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such photo of this product, no product.write, or the module is switched off'),
                '422' => $refused,
            ],
            summary: 'Puts a removed photo back',
            parameters: [$uuid('companyId', 'The company'), $uuid('productId', 'The product'), $uuid('photoId', 'The photo')],
        )));

        $paths->addPath($collection.'/{photoId}/content', new PathItem(get: new Operation(
            operationId: 'productPhotoContent',
            tags: ['ProductPhoto'],
            responses: [
                '200' => new Response('The picture: a small or large WebP copy cut by the server, or the photo as it was sent', new \ArrayObject([
                    'image/webp' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                    'image/jpeg' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                    'image/png' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                ])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such photo of this product, no product.read, a size that is not one, or the module is switched off'),
            ],
            summary: 'A picture of a product\'s photo',
            parameters: [
                $uuid('companyId', 'The company'), $uuid('productId', 'The product'), $uuid('photoId', 'The photo'),
                new Parameter('size', 'query', 'small (160 px on its longest side by default), large (960 px), or original; large when left out', false, schema: ['type' => 'string', 'enum' => ['small', 'large', 'original']]),
            ],
        )));

        return $openApi;
    }
}
