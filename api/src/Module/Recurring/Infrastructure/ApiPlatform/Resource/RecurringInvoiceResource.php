<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Recurring\Application\RecurringModel;
use App\Module\Recurring\Domain\Model\RecurringInvoice;
use App\Module\Recurring\Infrastructure\ApiPlatform\State\Processor\CreateRecurringInvoiceProcessor;
use App\Module\Recurring\Infrastructure\ApiPlatform\State\Processor\DeleteRecurringInvoiceProcessor;
use App\Module\Recurring\Infrastructure\ApiPlatform\State\Processor\ReviseRecurringInvoiceProcessor;
use App\Module\Recurring\Infrastructure\ApiPlatform\State\Provider\RecurringInvoiceCollectionProvider;
use App\Module\Recurring\Infrastructure\ApiPlatform\State\Provider\RecurringInvoiceItemProvider;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use App\Shared\Infrastructure\ApiPlatform\Payload\RequestedSchedule;
use App\Shared\Infrastructure\ApiPlatform\Payload\ScheduleRequest;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's recurring invoices: read with invoice.read, made from one of its invoices, revised and deleted with
 * invoice.write. The schedule is written flat, as a recurring expense's is: every so many days, weeks, months or years,
 * a month or a year on the day it is anchored to. A schedule that cannot recur, a model that is not an invoice of the
 * company that can be copied, or a first day already past answers 422 on its field with the reason.
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
            denormalizationContext: ['groups' => [self::REVISE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::REVISE, RecordVersion::REVISE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/recurring-invoices/{recurringInvoiceId}',
            processor: DeleteRecurringInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class RecurringInvoiceResource implements ScheduleRequest
{
    public const string READ = 'recurring_invoice:read';
    public const string CREATE = 'recurring_invoice:create';
    public const string REVISE = 'recurring_invoice:revise';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The invoice each draft copies. */
    #[Assert\NotBlank(groups: [self::CREATE], payload: ['reason' => 'value_required'])]
    #[Assert\Uuid(groups: [self::CREATE], payload: ['reason' => 'unknown_choice'])]
    #[Groups([self::READ, self::CREATE])]
    public string $modelInvoiceId = '';

    /** The model's number, null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $modelNumber = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $customerName = '';

    /** One of day, week, month, year. */
    #[Assert\NotBlank(groups: [self::CREATE, self::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Choice(callback: [RequestedSchedule::class, 'units'], groups: [self::CREATE, self::REVISE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public string $unit = '';

    /** Every so many units, 1 to 99. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public int $every = 1;

    /** A month's or a year's day: date, last_day, weekday or last_weekday; null for a day or a week. */
    #[Assert\Choice(callback: [RequestedSchedule::class, 'anchors'], groups: [self::CREATE, self::REVISE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?string $anchor = null;

    /** The date of the month a date anchor keeps, 1 to 31. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $monthDay = null;

    /** Which of the month's weekdays a weekday anchor reads, 1 to 4. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $ordinal = null;

    /** The day of the week, 1 for Monday to 7 for Sunday. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $weekday = null;

    /** The first occurrence's earliest day, the company's own. */
    #[Assert\NotBlank(groups: [self::CREATE], payload: ['reason' => 'value_required'])]
    #[Assert\Date(groups: [self::CREATE], payload: ['reason' => 'not_a_date'])]
    #[Groups([self::READ, self::CREATE])]
    public string $startsOn = '';

    /** The last day an occurrence may fall on; null when it goes on. */
    #[Assert\Date(groups: [self::CREATE, self::REVISE], payload: ['reason' => 'not_a_date'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?string $endsOn = null;

    #[Groups([self::READ, self::REVISE])]
    public bool $paused = false;

    /** The version read; a revision or a pause sends back the one it was read at, and one saved since, a run's included, answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

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

    public static function of(RecurringInvoice $recurring, ?RecurringModel $model): self
    {
        $resource = new self();
        $resource->id = $recurring->getId()->toRfc4122();
        $resource->modelInvoiceId = $recurring->getModelInvoiceId()->toRfc4122();
        $resource->modelNumber = $model?->number;
        $resource->customerName = $model->customerName ?? '';
        $recurrence = $recurring->getRecurrence();
        $schedule = $recurrence->schedule;
        $resource->unit = $schedule->unit->value;
        $resource->every = $schedule->every;
        $resource->anchor = $schedule->anchor?->value;
        $resource->monthDay = $schedule->monthDay;
        $resource->ordinal = $schedule->ordinal;
        $resource->weekday = $schedule->weekday;
        $resource->startsOn = $recurrence->startsOn->format('Y-m-d');
        $resource->endsOn = $recurrence->endsOn?->format('Y-m-d');
        $resource->paused = $recurrence->paused;
        $resource->version = $recurring->getVersion();
        $resource->nextOn = $recurrence->nextOn?->format('Y-m-d');
        $resource->drafted = $recurrence->drafted;
        $resource->lastInvoiceId = $recurring->getLastInvoiceId()?->toRfc4122();

        return $resource;
    }
}
