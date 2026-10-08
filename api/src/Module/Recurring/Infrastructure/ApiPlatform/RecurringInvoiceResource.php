<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Recurring\Application\RecurringModel;
use App\Module\Recurring\Domain\RecurringFrequency;
use App\Module\Recurring\Domain\RecurringInvoice;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's recurring invoices: read with invoice.read, made from one of its invoices, revised and deleted with
 * invoice.write. A model that is not an invoice of the company, or a first day already past, answers 422.
 */
#[ApiResource(
    shortName: 'RecurringInvoice',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/recurring-invoices',
            provider: RecurringInvoiceCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/recurring-invoices/{recurringInvoiceId}',
            provider: RecurringInvoiceItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/recurring-invoices',
            processor: CreateRecurringInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::CREATE]],
            validationContext: ['groups' => [self::CREATE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/recurring-invoices/{recurringInvoiceId}',
            processor: ReviseRecurringInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::REVISE]],
            validationContext: ['groups' => [self::REVISE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/recurring-invoices/{recurringInvoiceId}',
            processor: DeleteRecurringInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class RecurringInvoiceResource
{
    public const string READ = 'recurring_invoice:read';
    public const string CREATE = 'recurring_invoice:create';
    public const string REVISE = 'recurring_invoice:revise';
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The invoice each draft copies. */
    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Uuid(groups: [self::CREATE])]
    #[Groups([self::READ, self::CREATE])]
    public string $modelInvoiceId = '';

    /** The model's number, null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $modelNumber = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $customerName = '';

    /** One of weekly, monthly, quarterly, yearly. */
    #[Assert\NotBlank(groups: [self::CREATE, self::REVISE])]
    #[Assert\Choice(callback: [self::class, 'frequencies'], groups: [self::CREATE, self::REVISE])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public string $frequency = '';

    /** The first draft's day, the company's own; a month keeps its date. */
    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Regex(self::DAY, groups: [self::CREATE])]
    #[Groups([self::READ, self::CREATE])]
    public string $startsOn = '';

    /** The last day a draft may be made on; null when it goes on. */
    #[Assert\Regex(self::DAY, groups: [self::CREATE, self::REVISE])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?string $endsOn = null;

    #[Groups([self::READ, self::REVISE])]
    public bool $paused = false;

    /** The day of the next draft; null once past the last day. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $nextOn = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $drafted = 0;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $lastInvoiceId = null;

    /** @return list<string> */
    public static function frequencies(): array
    {
        return array_map(static fn (RecurringFrequency $frequency): string => $frequency->value, RecurringFrequency::cases());
    }

    public static function of(RecurringInvoice $recurring, ?RecurringModel $model): self
    {
        $resource = new self();
        $resource->id = $recurring->getId()->toRfc4122();
        $resource->modelInvoiceId = $recurring->getModelInvoiceId()->toRfc4122();
        $resource->modelNumber = $model?->number;
        $resource->customerName = $model->customerName ?? '';
        $resource->frequency = $recurring->getFrequency()->value;
        $resource->startsOn = $recurring->getStartsOn()->format('Y-m-d');
        $resource->endsOn = $recurring->getEndsOn()?->format('Y-m-d');
        $resource->paused = $recurring->isPaused();
        $resource->nextOn = $recurring->getNextOn()?->format('Y-m-d');
        $resource->drafted = $recurring->getDrafted();
        $resource->lastInvoiceId = $recurring->getLastInvoiceId()?->toRfc4122();

        return $resource;
    }
}
