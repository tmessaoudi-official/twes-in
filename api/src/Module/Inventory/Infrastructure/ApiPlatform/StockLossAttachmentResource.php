<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Files\Domain\Attachment;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The files a loss keeps, listed with stock.read and taken off with stock.write. A file goes up and comes back through
 * StockLossAttachmentsController, since those are bytes; StockLossAttachmentsOpenApi documents both.
 */
#[ApiResource(
    shortName: 'StockLossAttachment',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-movements/{movementId}/attachments',
            provider: StockLossAttachmentCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-movements/{movementId}/attachments/{attachmentId}',
            processor: DetachStockLossAttachmentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockLossAttachmentResource
{
    public const string READ = 'stock_loss_attachment:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    /** The name the file was sent under, without any path. */
    #[Groups([self::READ])]
    public string $name = '';

    /** The type read from the file's bytes. */
    #[Groups([self::READ])]
    public string $mime = '';

    /** Bytes. */
    #[Groups([self::READ])]
    public int $size = 0;

    #[Groups([self::READ])]
    public string $createdAt = '';

    public static function of(Attachment $attachment): self
    {
        $resource = new self();
        $resource->id = $attachment->getId()->toRfc4122();
        $resource->name = $attachment->getFile()->getOriginalName();
        $resource->mime = $attachment->getFile()->getMime();
        $resource->size = $attachment->getFile()->getSize();
        $resource->createdAt = $attachment->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    /** @return array{id: string, name: string, mime: string, size: int, createdAt: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'createdAt' => $this->createdAt];
    }
}
