<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Files\Domain\Attachment;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The files attached to a quote, such as the signed copy « Marquer accepté » asks for, listed with quote.read and
 * detached with quote.write whatever the quote's status. A file goes up and comes back through
 * QuoteAttachmentsController, since those are bytes; QuotesOpenApi documents both.
 */
#[ApiResource(
    shortName: 'QuoteAttachment',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/attachments',
            provider: QuoteAttachmentCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/attachments/{attachmentId}',
            processor: DetachQuoteAttachmentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class QuoteAttachmentResource
{
    public const string READ = 'quote_attachment:read';

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
