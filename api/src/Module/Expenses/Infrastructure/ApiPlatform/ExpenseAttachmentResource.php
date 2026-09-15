<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Files\Domain\Attachment;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The files an expense rests on, listed with expense.read and detached with expense.write while the expense is a
 * draft (409 otherwise). A file goes up and comes back through ExpenseAttachmentsController, since those are bytes;
 * ExpensesOpenApi documents both.
 */
#[ApiResource(
    shortName: 'ExpenseAttachment',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}/attachments',
            provider: ExpenseAttachmentCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}/attachments/{attachmentId}',
            processor: DetachExpenseAttachmentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ExpenseAttachmentResource
{
    public const string READ = 'expense_attachment:read';

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
