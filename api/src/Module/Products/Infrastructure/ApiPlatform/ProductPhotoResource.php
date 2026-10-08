<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Module\Products\Domain\PhotoSize;
use App\Module\Products\Domain\ProductPhoto;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A product's photos, listed in their order with product.read and arranged with product.write: put in an order, one
 * marked main and removed. A photo goes up, comes back as bytes and is put back after a removal through
 * ProductPhotosController, whose refusals carry a code for the screen; ProductPhotosOpenApi documents them.
 */
#[ApiResource(
    shortName: 'ProductPhoto',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products/{productId}/photos',
            provider: ProductPhotoCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/products/{productId}/photos/order',
            openapi: new OpenApiOperation(summary: 'Puts the photos of a product in this order.', description: 'Names every photo of the gallery once, first to last; which one is main does not change. 409 when the list is not exactly the photos there now: one was added or removed since they were read.'),
            status: 204,
            processor: OrderProductPhotosProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: ProductPhotoOrder::class,
            output: false,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/products/{productId}/photos/{photoId}/main',
            openapi: new OpenApiOperation(summary: 'Makes this photo the main one.', description: 'The main photo is the one shown wherever the product is picked or seen; the order does not change.'),
            status: 204,
            processor: MarkMainProductPhotoProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            output: false,
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/products/{productId}/photos/{photoId}',
            openapi: new OpenApiOperation(summary: 'Takes a photo out of the gallery.', description: 'The photo is kept, so …/restore puts it back; removing the main one makes the first of the rest main.'),
            processor: RemoveProductPhotoProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ProductPhotoResource
{
    public const string READ = 'product_photo:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    /** The name the picture was sent under, without any path. */
    #[Groups([self::READ])]
    public string $name = '';

    /** The type read from the picture's bytes. */
    #[Groups([self::READ])]
    public string $mime = '';

    /** The original's bytes. */
    #[Groups([self::READ])]
    public int $size = 0;

    /** The original's size in pixels, upright. */
    #[Groups([self::READ])]
    public int $width = 0;

    #[Groups([self::READ])]
    public int $height = 0;

    /** Whether this is the photo shown wherever the product is picked or seen. */
    #[Groups([self::READ])]
    public bool $main = false;

    #[Groups([self::READ])]
    public string $createdAt = '';

    public static function of(ProductPhoto $photo): self
    {
        $original = $photo->file(PhotoSize::Original);
        $resource = new self();
        $resource->id = $photo->getId()->toRfc4122();
        $resource->name = $original->getOriginalName();
        $resource->mime = $original->getMime();
        $resource->size = $original->getSize();
        $resource->width = $photo->getWidth();
        $resource->height = $photo->getHeight();
        $resource->main = $photo->isMain();
        $resource->createdAt = $photo->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    /** @return array{id: string, name: string, mime: string, size: int, width: int, height: int, main: bool, createdAt: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'width' => $this->width, 'height' => $this->height, 'main' => $this->main, 'createdAt' => $this->createdAt];
    }
}
